import 'leaflet/dist/leaflet.css'
import clsx from 'clsx'
import L from 'leaflet'
import { Clock, Footprints, History, MapPin, Pause, Play, Route as RouteIcon, ShieldAlert, Square } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { CircleMarker, MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap } from 'react-leaflet'
import { useSearchParams } from 'react-router'
import { Avatar, EmptyState, ErrorNotice, Input, Select, Spinner } from '../components/ui'
import { formatDistance, formatDuration, formatTime, initials, todayIn, zonedToUtcIso } from '../lib/format'
import { useRouteHistory, useShifts, useUsers, type RoutePoint, type RouteSegment, type RouteStop } from '../lib/queries'

const TILE_URL = import.meta.env.VITE_MAP_TILE_URL ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
const TILE_ATTRIBUTION = import.meta.env.VITE_MAP_TILE_ATTRIBUTION ?? '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
const DEFAULT_CENTER: [number, number] = [24.7136, 46.6753]
const DEFAULT_TZ = 'Asia/Riyadh'
const SPEEDS = [10, 60, 300]

interface Located {
  lat: number
  lng: number
  speed: number | null
  segment: RouteSegment
}

/** Where the technician was at time t: interpolated along the cleaned route, or null when off shift. */
function positionAt(segments: RouteSegment[], t: number): Located | null {
  for (const segment of segments) {
    const pts = segment.points
    if (!pts.length || t < pts[0]!.t || t > pts[pts.length - 1]!.t) continue
    let lo = 0
    let hi = pts.length - 1
    while (hi - lo > 1) {
      const mid = (lo + hi) >> 1
      if (pts[mid]!.t <= t) lo = mid
      else hi = mid
    }
    const a = pts[lo]!
    const b = pts[hi]!
    const f = b.t === a.t ? 0 : (t - a.t) / (b.t - a.t)
    return { lat: a.lat + (b.lat - a.lat) * f, lng: a.lng + (b.lng - a.lng) * f, speed: a.stop !== null && a.stop === b.stop ? 0 : (a.speed ?? null), segment }
  }
  return null
}

function stopIcon(n: number, color: string): L.DivIcon {
  return L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [13, 13], html: `<div class="route-stop" style="--team:${color}">${n}</div>` })
}

function endIcon(kind: 'start' | 'end'): L.DivIcon {
  return L.divIcon({ className: '', iconSize: [22, 22], iconAnchor: [11, 11], html: `<div class="route-end route-end--${kind}"></div>` })
}

function playheadIcon(name: string, color: string): L.DivIcon {
  const text = initials(name).replace(/[&<>"']/g, '')
  return L.divIcon({ className: '', iconSize: [38, 38], iconAnchor: [19, 19], html: `<div class="fleet-pin is-selected" style="--team:${color};--status:#10b981;width:38px;height:38px"><span class="fleet-pin__face">${text}</span></div>` })
}

function FitRoute({ segments, focus }: { segments: RouteSegment[]; focus: RouteStop | null }) {
  const map = useMap()
  const key = segments.map((s) => s.assignmentId + s.points.length).join()
  useEffect(() => {
    const all = segments.flatMap((s) => s.points)
    if (!all.length) return
    map.invalidateSize()
    map.fitBounds(L.latLngBounds(all.map((p) => [p.lat, p.lng])), { padding: [50, 50], maxZoom: 17 })
    // Refit only when the route itself changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, map])
  useEffect(() => {
    if (focus) map.flyTo([focus.lat, focus.lng], Math.max(map.getZoom(), 17), { duration: 0.6 })
  }, [focus, map])
  return null
}

function TrackSize() {
  const map = useMap()
  useEffect(() => {
    const o = new ResizeObserver(() => map.invalidateSize())
    o.observe(map.getContainer())
    return () => o.disconnect()
  }, [map])
  return null
}

function Stat({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="rounded-lg bg-slate-50 px-3 py-2">
      <p className="flex items-center gap-1.5 text-xs text-slate-500">
        {icon}
        {label}
      </p>
      <p className="mt-0.5 font-semibold tabular-nums text-slate-900">{value}</p>
    </div>
  )
}

export function RouteHistoryPage() {
  const [params, setParams] = useSearchParams()
  const shifts = useShifts()
  const tz = shifts.data?.[0]?.timezone ?? DEFAULT_TZ
  const technicians = useUsers({ role: 'TECHNICIAN', perPage: 200 })

  const userId = params.get('userId') ?? ''
  const date = params.get('date') ?? todayIn(tz)
  const fromTime = params.get('from') ?? '00:00'
  const toTime = params.get('to') ?? ''
  const wholeDay = !params.get('from') && !params.get('to')

  const set = (changes: Record<string, string>) =>
    setParams((p) => {
      for (const [k, v] of Object.entries(changes)) {
        if (v) p.set(k, v)
        else p.delete(k)
      }
      return p
    })

  const range = useMemo(() => {
    if (!userId) return null
    const from = zonedToUtcIso(date, fromTime, tz)
    const end = toTime ? zonedToUtcIso(date, toTime, tz) : new Date(new Date(zonedToUtcIso(date, '00:00', tz)).getTime() + 86_400_000).toISOString()
    return { userId, from, to: end }
  }, [userId, date, fromTime, toTime, tz])

  const history = useRouteHistory(range)
  const data = history.data
  const color = data?.user.department?.colorHex ?? '#64748b'
  const segments = useMemo(() => data?.segments.filter((s) => s.points.length) ?? [], [data])
  const allStops = useMemo(() => segments.flatMap((s) => s.stops), [segments])

  // ---- playback ------------------------------------------------------------
  const first = segments[0]?.points[0]?.t ?? 0
  const lastSegment = segments[segments.length - 1]
  const last = lastSegment?.points[lastSegment.points.length - 1]?.t ?? 0
  const [t, setT] = useState<number | null>(null)
  const [playing, setPlaying] = useState(false)
  const [speed, setSpeed] = useState(60)
  const [focus, setFocus] = useState<RouteStop | null>(null)
  const cursor = t === null ? last : Math.min(Math.max(t, first), last)
  const here = segments.length ? positionAt(segments, cursor) : null

  // Reset playback when another technician or day is loaded.
  const dataKey = `${data?.user.id}|${data?.from}|${data?.to}`
  const lastKey = useRef(dataKey)
  useEffect(() => {
    if (lastKey.current === dataKey) return
    lastKey.current = dataKey
    setT(null)
    setPlaying(false)
  }, [dataKey])

  useEffect(() => {
    if (!playing) return
    const id = setInterval(() => {
      setT((prev) => {
        const next = (prev === null || prev >= last ? first : prev) + 200 * speed
        if (next >= last) setPlaying(false)
        return Math.min(next, last)
      })
    }, 200)
    return () => clearInterval(id)
  }, [playing, speed, first, last])

  const people = technicians.data?.data ?? []
  const iso = (ms: number) => new Date(ms).toISOString()
  const totals = data?.totals

  return (
    <div className="flex h-full min-h-0 flex-col lg:flex-row">
      <section className="flex max-h-[45vh] shrink-0 flex-col overflow-y-auto border-b border-slate-200 bg-white lg:max-h-none lg:w-96 lg:border-r lg:border-b-0">
        <div className="space-y-3 border-b border-slate-100 p-4">
          <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <History className="size-5 text-brand-600" />
            Route history
          </h1>
          <Select value={userId} onChange={(e) => set({ userId: e.target.value })} aria-label="Technician">
            <option value="">Choose a technician…</option>
            {people.map((p) => (
              <option key={p.id} value={p.id}>
                {p.fullName}
                {p.department ? ` · ${p.department.name}` : ''}
              </option>
            ))}
          </Select>
          <Input type="date" value={date} max={todayIn(tz)} onChange={(e) => set({ date: e.target.value })} aria-label="Day" />
          <div className="flex items-center gap-2">
            <button
              onClick={() => set({ from: '', to: '' })}
              className={clsx('rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset', wholeDay ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'text-slate-600 ring-slate-300')}
            >
              Whole day
            </button>
            <Input type="time" value={fromTime} onChange={(e) => set({ from: e.target.value || '00:00' })} className="w-auto" aria-label="From time" />
            <span className="text-slate-400">–</span>
            <Input type="time" value={toTime} onChange={(e) => set({ to: e.target.value })} className="w-auto" aria-label="To time" />
          </div>
          <p className="text-xs text-slate-500">Times in {tz}</p>
        </div>

        {!userId ? (
          <EmptyState icon={<RouteIcon className="size-6" />} title="Pick a technician" description="See where they went on any day, replay it, and check how long they stopped where." />
        ) : history.isLoading ? (
          <div className="flex justify-center py-10">
            <Spinner />
          </div>
        ) : history.error ? (
          <div className="p-4">
            <ErrorNotice error={history.error} />
          </div>
        ) : data && totals ? (
          <>
            <div className="flex items-center gap-3 border-b border-slate-100 p-4">
              <Avatar name={data.user.fullName} color={data.user.department?.colorHex} />
              <div>
                <p className="font-medium text-slate-900">{data.user.fullName}</p>
                <p className="text-xs text-slate-500">{data.user.department?.name ?? 'No team'}</p>
              </div>
            </div>

            {segments.length === 0 ? (
              <EmptyState icon={<MapPin className="size-6" />} title="No route for this time" description="This technician wasn't checked in, or no location was recorded." />
            ) : (
              <>
                <div className="grid grid-cols-2 gap-2 p-4">
                  <Stat icon={<RouteIcon className="size-3.5" />} label="Distance" value={formatDistance(totals.distanceMeters)} />
                  <Stat icon={<Footprints className="size-3.5" />} label="Moving" value={formatDuration(totals.movingSeconds)} />
                  <Stat icon={<Square className="size-3.5" />} label="Stopped" value={formatDuration(totals.stoppedSeconds)} />
                  <Stat icon={<MapPin className="size-3.5" />} label="Stops" value={String(totals.stops)} />
                </div>
                {totals.droppedPoints > 0 && (
                  <p className="mx-4 mb-3 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                    {totals.droppedPoints} of {totals.rawPoints} GPS readings were too inaccurate to draw (common indoors or between tall buildings).
                  </p>
                )}

                <h2 className="px-4 pt-1 text-xs font-medium tracking-wide text-slate-500 uppercase">Shifts</h2>
                <ul className="px-4 pt-2 pb-3">
                  {data.segments.map((s) => (
                    <li key={s.assignmentId} className="flex justify-between py-1 text-sm">
                      <span className="text-slate-900">{s.shiftName ?? 'Shift'}</span>
                      <span className="tabular-nums text-slate-500">
                        {formatTime(s.checkedInAt, tz)} – {s.inProgress ? 'now' : formatTime(s.checkedOutAt, tz)}
                        {s.source === 'summary' && <span className="ml-1 text-xs text-slate-400">(simplified)</span>}
                      </span>
                    </li>
                  ))}
                </ul>

                <h2 className="px-4 text-xs font-medium tracking-wide text-slate-500 uppercase">Stops</h2>
                {allStops.length === 0 ? (
                  <p className="px-4 py-3 text-sm text-slate-500">No stops of 2 minutes or more.</p>
                ) : (
                  <ol className="divide-y divide-slate-100 px-2 pb-4">
                    {allStops.map((s, i) => (
                      <li key={s.from}>
                        <button
                          onClick={() => {
                            setFocus(s)
                            setT(s.from)
                            setPlaying(false)
                          }}
                          className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-slate-50"
                        >
                          <span className="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style={{ backgroundColor: color }}>
                            {i + 1}
                          </span>
                          <span className="flex-1 text-sm tabular-nums text-slate-700">
                            {formatTime(iso(s.from), tz)} – {formatTime(iso(s.to), tz)}
                          </span>
                          <span className="text-sm font-medium text-slate-900">{formatDuration(s.durationSeconds)}</span>
                        </button>
                      </li>
                    ))}
                  </ol>
                )}
              </>
            )}
          </>
        ) : null}
      </section>

      <section className="relative min-h-[24rem] flex-1">
        <MapContainer center={DEFAULT_CENTER} zoom={12} maxZoom={19} className="absolute inset-0 z-0" zoomControl={false}>
          <TileLayer url={TILE_URL} attribution={TILE_ATTRIBUTION} maxZoom={19} />
          <TrackSize />
          <FitRoute segments={segments} focus={focus} />
          {segments.map((s) => (
            <Polyline key={s.assignmentId} positions={s.points.map((p: RoutePoint) => [p.lat, p.lng] as [number, number])} pathOptions={{ color, weight: 5, opacity: 0.8 }} />
          ))}
          {segments.map((s) => {
            const a = s.points[0]!
            const b = s.points[s.points.length - 1]!
            return [
              <Marker key={`${s.assignmentId}-start`} position={[a.lat, a.lng]} icon={endIcon('start')}>
                <Tooltip>Start {formatTime(iso(a.t), tz)}</Tooltip>
              </Marker>,
              !s.inProgress && (
                <Marker key={`${s.assignmentId}-end`} position={[b.lat, b.lng]} icon={endIcon('end')}>
                  <Tooltip>End {formatTime(iso(b.t), tz)}</Tooltip>
                </Marker>
              ),
            ]
          })}
          {allStops.map((s, i) => (
            <Marker key={s.from} position={[s.lat, s.lng]} icon={stopIcon(i + 1, color)}>
              <Tooltip direction="top" offset={[0, -12]}>
                Stop {i + 1}: {formatDuration(s.durationSeconds)} ({formatTime(iso(s.from), tz)} – {formatTime(iso(s.to), tz)})
              </Tooltip>
            </Marker>
          ))}
          {here && data && t !== null && <Marker position={[here.lat, here.lng]} icon={playheadIcon(data.user.fullName, color)} zIndexOffset={2000} />}
          {here && t === null && <CircleMarker center={[here.lat, here.lng]} radius={6} pathOptions={{ color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 }} />}
        </MapContainer>

        {segments.length > 0 && (
          <div className="absolute inset-x-3 bottom-3 z-[500] rounded-xl bg-white/95 p-3 shadow-lg ring-1 ring-slate-200 sm:inset-x-auto sm:left-1/2 sm:w-[36rem] sm:-translate-x-1/2">
            <div className="flex items-center gap-3">
              <button
                onClick={() => {
                  if (!playing && (t === null || t >= last)) setT(first)
                  setPlaying((p) => !p)
                }}
                className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white hover:bg-brand-700"
                aria-label={playing ? 'Pause' : 'Play'}
              >
                {playing ? <Pause className="size-5" /> : <Play className="size-5 translate-x-px" />}
              </button>
              <input
                type="range"
                min={first}
                max={last}
                step={1000}
                value={cursor}
                onChange={(e) => {
                  setT(Number(e.target.value))
                  setPlaying(false)
                }}
                className="flex-1 accent-brand-600"
                aria-label="Time"
              />
              <Select value={String(speed)} onChange={(e) => setSpeed(Number(e.target.value))} className="w-auto py-1.5" aria-label="Playback speed">
                {SPEEDS.map((s) => (
                  <option key={s} value={s}>
                    {s}×
                  </option>
                ))}
              </Select>
            </div>
            <div className="mt-1.5 flex justify-between text-xs text-slate-500">
              <span className="flex items-center gap-1 font-medium text-slate-900">
                <Clock className="size-3.5" />
                {formatTime(iso(cursor), tz)}
              </span>
              <span>{here ? (here.speed ? `${Math.round(here.speed * 3.6)} km/h` : 'stopped') : 'off shift'}</span>
            </div>
          </div>
        )}
      </section>
    </div>
  )
}

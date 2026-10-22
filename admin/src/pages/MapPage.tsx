import 'leaflet/dist/leaflet.css'
import clsx from 'clsx'
import L from 'leaflet'
import { BatteryCharging, BatteryLow, BatteryMedium, Clock, Compass, Gauge, LocateFixed, Phone, Search, User as UserIcon, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { MapContainer, Marker, Polyline, TileLayer, useMap } from 'react-leaflet'
import { Link } from 'react-router'
import { Avatar, Input, Select, Spinner } from '../components/ui'
import { useLiveFleet, type RealtimeStatus } from '../lib/fleet'
import { formatAge, formatTime, initials } from '../lib/format'
import { useDepartments, useTrail } from '../lib/queries'
import type { FleetMember, Freshness } from '../lib/types'

// Free OpenStreetMap tiles by default; set VITE_MAP_TILE_URL for a commercial provider.
const TILE_URL = import.meta.env.VITE_MAP_TILE_URL ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
const TILE_ATTRIBUTION = import.meta.env.VITE_MAP_TILE_ATTRIBUTION ?? '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
const DEFAULT_CENTER: [number, number] = [24.7136, 46.6753] // Riyadh
const NO_TEAM_COLOR = '#64748b'

const STATUS_COLOR: Record<Freshness, string> = { LIVE: '#10b981', LAGGING: '#f59e0b', OFFLINE: '#94a3b8' }
const STATUS_LABEL: Record<Freshness, string> = { LIVE: 'Live', LAGGING: 'Lagging', OFFLINE: 'Offline' }

type Member = FleetMember & { position: NonNullable<FleetMember['position']> }

/** Spec 4.4 pin: team-colored ring, initials inside, status dot (pulsing while live). */
function pinIcon(m: FleetMember, selected: boolean): L.DivIcon {
  const team = m.department?.colorHex ?? NO_TEAM_COLOR
  const size = selected ? 46 : 38
  return L.divIcon({
    className: '',
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
    html: `
      <div class="fleet-pin ${selected ? 'is-selected' : ''} ${m.freshness === 'OFFLINE' ? 'is-offline' : ''}" style="--team:${team};--status:${STATUS_COLOR[m.freshness]};width:${size}px;height:${size}px">
        <span class="fleet-pin__face">${initials(m.fullName)}</span>
        <span class="fleet-pin__dot ${m.freshness === 'LIVE' ? 'is-live' : ''}"></span>
      </div>`,
  })
}

/** Leaflet measures its container once; re-measure whenever the container resizes. */
function TrackContainerSize() {
  const map = useMap()
  useEffect(() => {
    const observer = new ResizeObserver(() => map.invalidateSize())
    observer.observe(map.getContainer())
    return () => observer.disconnect()
  }, [map])
  return null
}

function FitToFleet({ members }: { members: Member[] }) {
  const map = useMap()
  const done = useRef(false)
  useEffect(() => {
    if (done.current || !members.length) return
    map.invalidateSize()
    if (map.getSize().y < 50) return // not laid out yet; try again on the next update
    done.current = true
    if (members.length === 1) map.setView([members[0]!.position.latitude, members[0]!.position.longitude], 15)
    else map.fitBounds(L.latLngBounds(members.map((m) => [m.position.latitude, m.position.longitude])), { padding: [60, 60], maxZoom: 15 })
  }, [members, map])
  return null
}

function FlyTo({ target }: { target: Member | null }) {
  const map = useMap()
  const last = useRef<string | null>(null)
  useEffect(() => {
    if (!target || last.current === target.userId) return
    last.current = target.userId
    map.flyTo([target.position.latitude, target.position.longitude], Math.max(map.getZoom(), 15), { duration: 0.8 })
  }, [target, map])
  useEffect(() => {
    if (!target) last.current = null
  }, [target])
  return null
}

function useElapsed(since: string): string {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])
  const s = Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000))
  return `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m ${String(s % 60).padStart(2, '0')}s`
}

function Drawer({ member, timezone, onClose }: { member: FleetMember; timezone?: string; onClose: () => void }) {
  const elapsed = useElapsed(member.checkedInAt)
  const p = member.position
  const battery = p?.batteryLevel
  const BatteryIcon = p?.isCharging ? BatteryCharging : battery != null && battery <= 20 ? BatteryLow : BatteryMedium

  const stats = [
    { icon: <Clock className="size-4" />, label: 'On shift for', value: elapsed },
    { icon: <Gauge className="size-4" />, label: 'Speed', value: p?.speed != null ? `${Math.round(p.speed * 3.6)} km/h` : '—' },
    { icon: <BatteryIcon className={clsx('size-4', battery != null && battery <= 20 && 'text-red-600')} />, label: 'Battery', value: battery != null ? `${battery}%${p?.isCharging ? ' (charging)' : ''}` : '—' },
    { icon: <Compass className="size-4" />, label: 'Heading', value: p?.heading != null ? `${Math.round(p.heading)}°` : '—' },
    { icon: <LocateFixed className="size-4" />, label: 'Accuracy', value: p?.accuracy != null ? `±${Math.round(p.accuracy)} m` : '—' },
  ]

  return (
    // A side column beside the map (not over it), so the selected pin is never hidden.
    <aside className="flex max-h-80 w-full shrink-0 flex-col overflow-hidden border-t border-slate-200 bg-white sm:max-h-none sm:w-80 sm:border-t-0 sm:border-l">
      <div className="flex items-start gap-3 border-b border-slate-100 p-4">
        <Avatar name={member.fullName} color={member.department?.colorHex} />
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold text-slate-900">{member.fullName}</p>
          <p className="truncate text-xs text-slate-500">
            {member.department?.name ?? 'No team'} · {member.shiftName}
          </p>
          <p className="mt-1 inline-flex items-center gap-1.5 text-xs font-medium" style={{ color: STATUS_COLOR[member.freshness] }}>
            <span className="size-2 rounded-full" style={{ backgroundColor: STATUS_COLOR[member.freshness] }} />
            {STATUS_LABEL[member.freshness]} · {formatAge(member.ageSeconds)}
          </p>
        </div>
        <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100" aria-label="Close details">
          <X className="size-5" />
        </button>
      </div>

      <dl className="divide-y divide-slate-100 overflow-y-auto">
        {stats.map((s) => (
          <div key={s.label} className="flex items-center gap-3 px-4 py-2.5 text-sm">
            <span className="text-slate-400">{s.icon}</span>
            <dt className="flex-1 text-slate-500">{s.label}</dt>
            <dd className="font-medium tabular-nums text-slate-900">{s.value}</dd>
          </div>
        ))}
        <div className="px-4 py-2.5 text-xs text-slate-500">
          Checked in {formatTime(member.checkedInAt, timezone)} · shift ends {formatTime(member.scheduledEndAt, timezone)}
        </div>
      </dl>

      <div className="mt-auto grid grid-cols-2 gap-2 border-t border-slate-100 p-3">
        {member.phone ? (
          <a href={`tel:${member.phone}`} className="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
            <Phone className="size-4" />
            Call
          </a>
        ) : (
          <span />
        )}
        <Link to={`/users/${member.userId}`} className="inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50">
          <UserIcon className="size-4" />
          Profile
        </Link>
      </div>
    </aside>
  )
}

function ConnectionBadge({ status }: { status: RealtimeStatus }) {
  const label = { live: 'Live updates', connecting: 'Connecting…', polling: 'Updating every 5s' }[status]
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-white/95 px-3 py-1 text-xs font-medium text-slate-600 shadow ring-1 ring-slate-200">
      <span className={clsx('size-2 rounded-full', status === 'live' ? 'animate-pulse bg-emerald-500' : 'bg-amber-400')} />
      {label}
    </span>
  )
}

export function MapPage() {
  const fleet = useLiveFleet()
  const departments = useDepartments()
  const [search, setSearch] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [statuses, setStatuses] = useState<Freshness[]>(['LIVE', 'LAGGING', 'OFFLINE'])
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return fleet.members.filter(
      (m) =>
        (!departmentId || m.department?.id === departmentId) &&
        statuses.includes(m.freshness) &&
        (!q || m.fullName.toLowerCase().includes(q) || m.phone?.includes(q)),
    )
  }, [fleet.members, search, departmentId, statuses])

  const onMap = filtered.filter((m): m is Member => m.position !== null)
  const selected = fleet.members.find((m) => m.userId === selectedId) ?? null
  const selectedOnMap = selected?.position ? (selected as Member) : null
  const trail = useTrail(selected?.assignmentId ?? null)

  // Search snaps to the first match (spec 4.4).
  useEffect(() => {
    if (search.trim() && onMap.length === 1) setSelectedId(onMap[0]!.userId)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const toggleStatus = (s: Freshness) => setStatuses((all) => (all.includes(s) ? all.filter((x) => x !== s) : [...all, s]))

  return (
    <div className="flex h-full min-h-0 flex-col lg:flex-row">
      {/* Fleet list */}
      {/* Hidden on mid-size screens while a technician is open, so the map keeps room. */}
      <section className={clsx('flex max-h-72 shrink-0 flex-col border-b border-slate-200 bg-white lg:max-h-none lg:w-80 lg:border-r lg:border-b-0', selected && 'max-xl:hidden')}>
        <div className="space-y-3 border-b border-slate-100 p-4">
          <div className="flex items-baseline justify-between">
            <h1 className="text-lg font-semibold text-slate-900">Live map</h1>
            <span className="text-sm text-slate-500">{fleet.counts.onShift} on shift</span>
          </div>
          <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Find by name or phone" className="pl-9" aria-label="Find technician" />
          </div>
          <Select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} aria-label="Department">
            <option value="">All teams</option>
            {departments.data?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
          <div className="flex gap-1.5">
            {(['LIVE', 'LAGGING', 'OFFLINE'] as Freshness[]).map((s) => (
              <button
                key={s}
                onClick={() => toggleStatus(s)}
                aria-pressed={statuses.includes(s)}
                className={clsx(
                  'flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium ring-1 ring-inset',
                  statuses.includes(s) ? 'bg-white text-slate-800 ring-slate-300' : 'bg-slate-50 text-slate-400 ring-slate-200',
                )}
              >
                <span className="size-2 rounded-full" style={{ backgroundColor: statuses.includes(s) ? STATUS_COLOR[s] : '#cbd5e1' }} />
                {STATUS_LABEL[s]} {fleet.counts[s.toLowerCase() as 'live' | 'lagging' | 'offline']}
              </button>
            ))}
          </div>
        </div>

        <ul className="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
          {fleet.isLoading && (
            <li className="flex justify-center py-8">
              <Spinner />
            </li>
          )}
          {!fleet.isLoading && !filtered.length && (
            <li className="px-4 py-8 text-center text-sm text-slate-500">{fleet.members.length ? 'Nobody matches these filters.' : 'Nobody is checked in right now.'}</li>
          )}
          {filtered.map((m) => (
            <li key={m.userId}>
              <button
                onClick={() => setSelectedId(m.userId)}
                className={clsx('flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-slate-50', selectedId === m.userId && 'bg-brand-50')}
              >
                <span className="relative">
                  <Avatar name={m.fullName} color={m.department?.colorHex} />
                  <span className="absolute -right-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-white" style={{ backgroundColor: STATUS_COLOR[m.freshness] }} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-slate-900">{m.fullName}</span>
                  <span className="block truncate text-xs text-slate-500">
                    {m.position ? formatAge(m.ageSeconds) : 'No location yet'} · {m.shiftName}
                  </span>
                </span>
                {m.position?.batteryLevel != null && (
                  <span className={clsx('text-xs tabular-nums', m.position.batteryLevel <= 20 ? 'font-medium text-red-600' : 'text-slate-400')}>{m.position.batteryLevel}%</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      </section>

      {/* Map */}
      <section className="flex min-h-[24rem] flex-1 flex-col sm:flex-row">
        <div className="relative min-h-[16rem] flex-1">
        <MapContainer center={DEFAULT_CENTER} zoom={12} className="absolute inset-0 z-0" zoomControl={false}>
          <TileLayer url={TILE_URL} attribution={TILE_ATTRIBUTION} maxZoom={19} />
          <TrackContainerSize />
          <FitToFleet members={onMap} />
          <FlyTo target={selectedOnMap} />
          {selected && trail.data && trail.data.data.length > 1 && (
            <Polyline
              positions={trail.data.data.map((p) => [p.lat, p.lng] as [number, number])}
              pathOptions={{ color: selected.department?.colorHex ?? NO_TEAM_COLOR, weight: 4, opacity: 0.7 }}
            />
          )}
          {onMap.map((m) => (
            <Marker
              key={m.userId}
              position={[m.position.latitude, m.position.longitude]}
              icon={pinIcon(m, m.userId === selectedId)}
              zIndexOffset={m.userId === selectedId ? 1000 : m.freshness === 'LIVE' ? 100 : 0}
              eventHandlers={{ click: () => setSelectedId(m.userId) }}
              title={m.fullName}
            />
          ))}
        </MapContainer>

        <div className="pointer-events-none absolute top-3 left-3 z-[500]">
          <ConnectionBadge status={fleet.status} />
        </div>

        </div>
        {selected && <Drawer member={selected} timezone={fleet.timezone} onClose={() => setSelectedId(null)} />}
      </section>
    </div>
  )
}

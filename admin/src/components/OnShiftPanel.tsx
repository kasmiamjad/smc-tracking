import clsx from 'clsx'
import { BatteryCharging, BatteryLow, BatteryMedium, Gauge, Radio } from 'lucide-react'
import { Link } from 'react-router'
import { formatAge } from '../lib/format'
import { useLiveFleet } from '../lib/fleet'
import type { FleetMember, Freshness } from '../lib/types'
import { Avatar, Card, Spinner } from './ui'

// Spec 4.4: green = transmitting within 10s, amber = 10-30s, grey = offline.
const DOT: Record<Freshness, string> = {
  LIVE: 'bg-emerald-500',
  LAGGING: 'bg-amber-400',
  OFFLINE: 'bg-slate-300',
}

function Battery({ member }: { member: FleetMember }) {
  const level = member.position?.batteryLevel
  if (level == null) return null
  const Icon = member.position?.isCharging ? BatteryCharging : level <= 20 ? BatteryLow : BatteryMedium
  return (
    <span className={clsx('inline-flex items-center gap-1', level <= 20 ? 'text-red-600' : 'text-slate-500')}>
      <Icon className="size-4" />
      {level}%
    </span>
  )
}

export function OnShiftPanel() {
  const fleet = useLiveFleet()
  const meta = fleet.counts
  const members = [...fleet.members].sort((a, b) => (a.ageSeconds ?? 1e9) - (b.ageSeconds ?? 1e9))

  return (
    <Card className="flex flex-col">
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <div className="flex items-center gap-2">
          <Radio className="size-5 text-emerald-600" />
          <h2 className="font-semibold text-slate-900">On shift now</h2>
        </div>
        {meta && (
          <div className="flex gap-3 text-xs text-slate-500">
            {(['live', 'lagging', 'offline'] as const).map((k) => (
              <span key={k} className="inline-flex items-center gap-1">
                <span className={clsx('size-2 rounded-full', DOT[k.toUpperCase() as Freshness])} />
                {meta[k]} {k}
              </span>
            ))}
          </div>
        )}
      </div>

      {fleet.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : !members.length ? (
        <p className="px-5 py-10 text-center text-sm text-slate-500">Nobody is checked in right now.</p>
      ) : (
        <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
          {members.map((m) => (
            <li key={m.userId}>
              <Link to={`/users/${m.userId}`} className="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                <span className="relative">
                  <Avatar name={m.fullName} color={m.department?.colorHex} />
                  <span className={clsx('absolute -right-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-white', DOT[m.freshness], m.freshness === 'LIVE' && 'animate-pulse')} />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-slate-900">{m.fullName}</p>
                  <p className="truncate text-xs text-slate-500">
                    {m.shiftName} · {formatAge(m.ageSeconds)}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-3 text-xs">
                  {m.position?.speed != null && (
                    <span className="hidden items-center gap-1 text-slate-500 sm:inline-flex">
                      <Gauge className="size-4" />
                      {Math.round(m.position.speed * 3.6)} km/h
                    </span>
                  )}
                  <Battery member={m} />
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
      <p className="mt-auto border-t border-slate-100 px-5 py-2 text-xs text-slate-400">{fleet.status === 'live' ? 'Live updates' : 'Refreshes every 5 seconds'} · <Link to="/map" className="text-brand-600 hover:text-brand-700">Open live map</Link></p>
    </Card>
  )
}

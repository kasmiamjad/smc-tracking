import clsx from 'clsx'
import { CalendarPlus, ChevronLeft, ChevronRight, CalendarDays, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { AssignShiftModal } from '../components/AssignShiftModal'
import { useToast } from '../components/toast'
import { Avatar, Badge, Button, Card, EmptyState, ErrorNotice, LoadingBlock, Modal, PageHeader, Select } from '../components/ui'
import { useAuth } from '../lib/auth'
import { addDays, formatDateTime, formatDayHeading, formatTime, startOfWeek, toIsoDate } from '../lib/format'
import { useDepartments, useRemoveAssignment, useRoster } from '../lib/queries'
import type { AssignmentStatus, ShiftAssignment, User } from '../lib/types'

const STATUS_STYLE: Record<AssignmentStatus, string> = {
  SCHEDULED: 'bg-slate-100 text-slate-700 ring-slate-200',
  ACTIVE: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
  COMPLETED: 'bg-brand-50 text-brand-800 ring-brand-200',
  ABSENT: 'bg-red-50 text-red-700 ring-red-200',
}

const STATUS_LABEL: Record<AssignmentStatus, string> = { SCHEDULED: 'Scheduled', ACTIVE: 'On shift', COMPLETED: 'Completed', ABSENT: 'Absent' }

function AssignmentDetails({ assignment, timezone, onClose }: { assignment: ShiftAssignment; timezone: string; onClose: () => void }) {
  const { isAdmin } = useAuth()
  const remove = useRemoveAssignment()
  const toast = useToast()
  const a = assignment

  const rows: [string, string][] = [
    ['Scheduled', `${formatTime(a.scheduledStartAt, timezone)} – ${formatTime(a.scheduledEndAt, timezone)}`],
    ['Checked in', a.checkedInAt ? `${formatDateTime(a.checkedInAt)}${a.checkInOutsideZone ? ' (outside zone)' : ''}` : '—'],
    ['Checked out', a.checkedOutAt ? `${formatDateTime(a.checkedOutAt)}${a.autoCheckedOut ? ' (automatic)' : ''}` : '—'],
  ]
  if (a.workedHours !== null) rows.push(['Hours', `${a.workedHours} worked · ${a.regularHours} regular · ${a.overtimeHours} overtime`])

  return (
    <Modal
      open
      onClose={onClose}
      title={`${a.user?.fullName} · ${a.shift?.name}`}
      description={formatDayHeading(a.shiftDate).weekday + ' ' + formatDayHeading(a.shiftDate).day}
      footer={
        <>
          {isAdmin && a.status === 'SCHEDULED' && (
            <Button
              variant="danger"
              icon={<Trash2 className="size-4" />}
              loading={remove.isPending}
              onClick={() =>
                remove.mutate(a.id, {
                  onSuccess: () => {
                    toast.success('Shift removed.')
                    onClose()
                  },
                  onError: toast.error,
                })
              }
            >
              Remove from roster
            </Button>
          )}
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
        </>
      }
    >
      <div className="mb-3">
        <span className={clsx('rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', STATUS_STYLE[a.status])}>{STATUS_LABEL[a.status]}</span>
      </div>
      <dl className="space-y-2 text-sm">
        {rows.map(([k, v]) => (
          <div key={k} className="flex gap-4">
            <dt className="w-28 shrink-0 text-slate-500">{k}</dt>
            <dd className="text-slate-900">{v}</dd>
          </div>
        ))}
      </dl>
    </Modal>
  )
}

export function RosterPage() {
  const { isAdmin } = useAuth()
  const [params, setParams] = useSearchParams()
  const [today] = useState(() => toIsoDate(new Date()))
  const weekStart = startOfWeek(params.get('week') ?? today)
  const departmentId = params.get('departmentId') ?? ''
  const weekEnd = addDays(weekStart, 6)
  const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i))

  const roster = useRoster({ from: weekStart, to: weekEnd, departmentId: departmentId || undefined })
  const departments = useDepartments()
  const [assigning, setAssigning] = useState(false)
  const [open, setOpen] = useState<ShiftAssignment | null>(null)
  const timezone = roster.data?.meta.timezone

  const set = (key: string, value: string) =>
    setParams((p) => {
      if (value) p.set(key, value)
      else p.delete(key)
      return p
    })

  // One row per technician, one cell per day.
  const rows = useMemo(() => {
    const byUser = new Map<string, { user: User; cells: Record<string, ShiftAssignment[]> }>()
    for (const a of roster.data?.data ?? []) {
      if (!a.user) continue
      const row = byUser.get(a.userId) ?? { user: a.user, cells: {} }
      ;(row.cells[a.shiftDate] ??= []).push(a)
      byUser.set(a.userId, row)
    }
    return [...byUser.values()].sort((x, y) => x.user.fullName.localeCompare(y.user.fullName))
  }, [roster.data])

  const range = `${formatDayHeading(weekStart).day} – ${formatDayHeading(weekEnd).day}`

  return (
    <>
      <PageHeader
        title="Roster"
        description={`Who works when${timezone ? ` · times in ${timezone}` : ''}`}
        actions={
          isAdmin && (
            <Button icon={<CalendarPlus className="size-4" />} onClick={() => setAssigning(true)}>
              Assign shift
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="flex items-center gap-1">
            <Button variant="secondary" size="sm" icon={<ChevronLeft className="size-4" />} onClick={() => set('week', addDays(weekStart, -7))} aria-label="Previous week" />
            <Button variant="secondary" size="sm" icon={<ChevronRight className="size-4" />} onClick={() => set('week', addDays(weekStart, 7))} aria-label="Next week" />
          </div>
          <h2 className="font-semibold text-slate-900">{range}</h2>
          {weekStart !== startOfWeek(today) && (
            <Button variant="ghost" size="sm" onClick={() => set('week', '')}>
              This week
            </Button>
          )}
          <div className="ml-auto">
            <Select value={departmentId} onChange={(e) => set('departmentId', e.target.value)} className="w-auto" aria-label="Department">
              <option value="">All departments</option>
              {departments.data?.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <ErrorNotice error={roster.error} />

        {roster.isLoading ? (
          <LoadingBlock />
        ) : !rows.length ? (
          <EmptyState
            icon={<CalendarDays className="size-6" />}
            title="Nobody is rostered this week"
            description={isAdmin ? 'Use "Assign shift" to put technicians on a shift.' : undefined}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[56rem] table-fixed text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50">
                  <th className="w-52 px-4 py-2 text-left text-xs font-medium tracking-wide text-slate-500 uppercase">Technician</th>
                  {days.map((d) => {
                    const h = formatDayHeading(d)
                    return (
                      <th key={d} className={clsx('px-1 py-2 text-center text-xs font-medium', d === today ? 'text-brand-700' : 'text-slate-500')}>
                        <div className="tracking-wide uppercase">{h.weekday}</div>
                        <div className={clsx('font-normal', d === today && 'font-semibold')}>{h.day}</div>
                      </th>
                    )
                  })}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map(({ user, cells }) => (
                  <tr key={user.id}>
                    <td className="px-4 py-2">
                      <Link to={`/users/${user.id}`} className="flex items-center gap-2 hover:text-brand-700">
                        <Avatar name={user.fullName} color={user.department?.colorHex} />
                        <span className="truncate font-medium">{user.fullName}</span>
                      </Link>
                    </td>
                    {days.map((d) => (
                      <td key={d} className={clsx('px-1 py-2 align-top', d === today && 'bg-brand-50/40')}>
                        <div className="space-y-1">
                          {(cells[d] ?? []).map((a) => (
                            <button
                              key={a.id}
                              onClick={() => setOpen(a)}
                              className={clsx('block w-full rounded-md px-2 py-1 text-left ring-1 ring-inset transition hover:brightness-95', STATUS_STYLE[a.status])}
                            >
                              <div className="truncate text-xs font-semibold">{a.shift?.name}</div>
                              <div className="text-[11px] tabular-nums opacity-80">
                                {formatTime(a.scheduledStartAt, timezone)}–{formatTime(a.scheduledEndAt, timezone)}
                              </div>
                              {a.overtimeHours ? <div className="text-[11px] font-medium">+{a.overtimeHours}h OT</div> : null}
                            </button>
                          ))}
                        </div>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="flex flex-wrap gap-3 border-t border-slate-100 px-4 py-3">
          {(Object.keys(STATUS_LABEL) as AssignmentStatus[]).map((s) => (
            <span key={s} className="inline-flex items-center gap-1.5 text-xs text-slate-600">
              <span className={clsx('size-3 rounded ring-1 ring-inset', STATUS_STYLE[s])} />
              {STATUS_LABEL[s]}
            </span>
          ))}
          <Badge tone="gray">Click a shift for details</Badge>
        </div>
      </Card>

      {assigning && <AssignShiftModal defaultFrom={weekStart} onClose={() => setAssigning(false)} />}
      {open && timezone && <AssignmentDetails assignment={open} timezone={timezone} onClose={() => setOpen(null)} />}
    </>
  )
}

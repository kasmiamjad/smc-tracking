import clsx from 'clsx'
import { AlertTriangle, ChevronLeft, ChevronRight, Download, FileSpreadsheet, FileText, Sheet as SheetIcon } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useToast } from '../components/toast'
import { Avatar, Badge, Button, Card, EmptyState, ErrorNotice, Input, LoadingBlock, PageHeader, Select } from '../components/ui'
import { downloadFile } from '../lib/api'
import { addDays, formatDayHeading, formatTime, parseIsoDate, startOfWeek, toIsoDate } from '../lib/format'
import { timesheetExportPath, useDepartments, useTimesheet, type Period, type Timesheet, type TimesheetFilters } from '../lib/queries'

const TABS: { period: Period; label: string }[] = [
  { period: 'daily', label: 'Day' },
  { period: 'weekly', label: 'Week' },
  { period: 'monthly', label: 'Month' },
  { period: 'custom', label: 'Custom' },
]

const h = (hours: number) => hours.toFixed(2)

function startOfMonth(s: string): string {
  return s.slice(0, 8) + '01'
}

function shiftMonth(s: string, by: number): string {
  const d = parseIsoDate(startOfMonth(s))
  d.setMonth(d.getMonth() + by)
  return toIsoDate(d)
}

function Stat({ label, value, accent }: { label: string; value: ReactNode; accent?: string }) {
  return (
    <div className="rounded-lg bg-slate-50 px-4 py-3">
      <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
      <p className={clsx('mt-1 text-xl font-semibold tabular-nums', accent ?? 'text-slate-900')}>{value}</p>
    </div>
  )
}

function Person({ user }: { user: Timesheet['people'][number]['user'] }) {
  return (
    <Link to={`/users/${user.id}`} className="flex items-center gap-2 hover:text-brand-700">
      <Avatar name={user.fullName} color={user.department?.colorHex} />
      <span className="min-w-0">
        <span className="block truncate font-medium">{user.fullName}</span>
        <span className="block truncate text-xs text-slate-500">{user.department?.name ?? 'No team'}</span>
      </span>
    </Link>
  )
}

const TH = 'px-3 py-2.5 text-left text-xs font-medium tracking-wide text-slate-500 uppercase'
const NUM = 'px-3 py-2.5 text-right tabular-nums whitespace-nowrap'

function DailyTable({ sheet }: { sheet: Timesheet }) {
  const tz = sheet.timezone
  return (
    <table className="min-w-full divide-y divide-slate-100 text-sm">
      <thead className="bg-slate-50">
        <tr>
          <th className={TH}>Technician</th>
          <th className={TH}>Shift</th>
          <th className={TH}>Check-in</th>
          <th className={TH}>Check-out</th>
          <th className={clsx(TH, 'text-right')}>Worked</th>
          <th className={clsx(TH, 'text-right')}>Regular</th>
          <th className={clsx(TH, 'text-right')}>Overtime</th>
          <th className={clsx(TH, 'text-right')} title="Time moving vs stationary, from GPS">
            Moving / still
          </th>
          <th className={clsx(TH, 'text-right')} title="Share of worked time covered by GPS pings">
            GPS
          </th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {sheet.entries.map((e) => (
          <tr key={e.id} className={clsx(e.status === 'ABSENT' && 'bg-red-50/40')}>
            <td className="px-3 py-2.5">
              <Person user={e.user} />
            </td>
            <td className="px-3 py-2.5 whitespace-nowrap">
              <div>{e.shiftName}</div>
              <div className="text-xs text-slate-500">
                {formatTime(e.scheduledStartAt, tz)}–{formatTime(e.scheduledEndAt, tz)}
              </div>
            </td>
            {e.status === 'ABSENT' ? (
              <td colSpan={7} className="px-3 py-2.5">
                <Badge tone="red">Absent</Badge>
              </td>
            ) : (
              <>
                <td className="px-3 py-2.5 whitespace-nowrap">
                  {formatTime(e.checkedInAt, tz)}
                  {e.checkInOutsideZone && (
                    <span className="ml-1.5" title="Checked in outside the shift zone">
                      <AlertTriangle className="inline size-3.5 text-amber-500" />
                    </span>
                  )}
                </td>
                <td className="px-3 py-2.5 whitespace-nowrap">
                  {e.inProgress ? <Badge tone="green">In progress</Badge> : formatTime(e.checkedOutAt, tz)}
                  {e.autoCheckedOut && <span className="ml-1.5 text-xs text-amber-700">auto</span>}
                </td>
                <td className={clsx(NUM, 'font-medium')}>{h(e.workedHours)}</td>
                <td className={NUM}>{h(e.regularHours)}</td>
                <td className={clsx(NUM, e.overtimeHours > 0 && 'font-medium text-violet-700')}>{h(e.overtimeHours)}</td>
                <td className={clsx(NUM, 'text-slate-500')}>{e.movingHours === null ? '—' : `${h(e.movingHours)} / ${h(e.stationaryHours ?? 0)}`}</td>
                <td className={clsx(NUM, e.gpsCoverage !== null && e.gpsCoverage < 0.5 ? 'font-medium text-red-600' : 'text-slate-500')}>
                  {e.gpsCoverage === null ? '—' : `${Math.round(e.gpsCoverage * 100)}%`}
                </td>
              </>
            )}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function WeeklyTable({ sheet, today }: { sheet: Timesheet; today: string }) {
  return (
    <table className="min-w-full divide-y divide-slate-100 text-sm">
      <thead className="bg-slate-50">
        <tr>
          <th className={TH}>Technician</th>
          {sheet.dates.map((d) => (
            <th key={d} className={clsx(TH, 'text-right', d === today && 'text-brand-700')}>
              {formatDayHeading(d).weekday}
              <div className="font-normal normal-case">{formatDayHeading(d).day}</div>
            </th>
          ))}
          <th className={clsx(TH, 'text-right')}>Week total</th>
          <th className={clsx(TH, 'text-right')}>Overtime</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {sheet.people.map((p) => (
          <tr key={p.user.id}>
            <td className="px-3 py-2.5">
              <Person user={p.user} />
            </td>
            {sheet.dates.map((d) => (
              <td key={d} className={clsx(NUM, p.byDate[d] ? 'text-slate-900' : 'text-slate-300')}>
                {p.byDate[d] ? h(p.byDate[d]) : '–'}
              </td>
            ))}
            <td className={clsx(NUM, 'font-semibold')}>
              {h(p.workedHours)}
              {p.inProgress && <span className="ml-1 text-xs font-normal text-emerald-600">+</span>}
            </td>
            <td className={clsx(NUM, p.overtimeHours > 0 && 'font-medium text-violet-700')}>{h(p.overtimeHours)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function SummaryTable({ sheet }: { sheet: Timesheet }) {
  return (
    <table className="min-w-full divide-y divide-slate-100 text-sm">
      <thead className="bg-slate-50">
        <tr>
          <th className={TH}>Technician</th>
          <th className={clsx(TH, 'text-right')}>Days worked</th>
          <th className={clsx(TH, 'text-right')}>Absences</th>
          <th className={clsx(TH, 'text-right')}>Total hours</th>
          <th className={clsx(TH, 'text-right')}>Regular</th>
          <th className={clsx(TH, 'text-right')}>Overtime</th>
          <th className={clsx(TH, 'text-right')}>Avg per day</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {sheet.people.map((p) => (
          <tr key={p.user.id}>
            <td className="px-3 py-2.5">
              <Person user={p.user} />
            </td>
            <td className={NUM}>{p.daysWorked}</td>
            <td className={clsx(NUM, p.absences > 0 && 'font-medium text-red-600')}>{p.absences}</td>
            <td className={clsx(NUM, 'font-semibold')}>{h(p.workedHours)}</td>
            <td className={NUM}>{h(p.regularHours)}</td>
            <td className={clsx(NUM, p.overtimeHours > 0 && 'font-medium text-violet-700')}>{h(p.overtimeHours)}</td>
            <td className={NUM}>{h(p.averageDailyHours)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

export function TimesheetsPage() {
  const [params, setParams] = useSearchParams()
  const [today] = useState(() => toIsoDate(new Date()))
  const departments = useDepartments()
  const toast = useToast()
  const [exporting, setExporting] = useState<string | null>(null)

  const period = (params.get('period') as Period) || 'daily'
  const date = params.get('date') || today
  const endDate = params.get('end') || date
  const departmentId = params.get('departmentId') || ''

  const filters: TimesheetFilters = {
    period,
    startDate: period === 'weekly' ? startOfWeek(date) : period === 'monthly' ? startOfMonth(date) : date,
    endDate: period === 'custom' ? endDate : undefined,
    departmentId: departmentId || undefined,
  }
  const sheet = useTimesheet(filters)

  const set = (changes: Record<string, string>) =>
    setParams((p) => {
      for (const [k, v] of Object.entries(changes)) {
        if (v) p.set(k, v)
        else p.delete(k)
      }
      return p
    })

  function step(by: number) {
    const next = period === 'daily' ? addDays(date, by) : period === 'weekly' ? addDays(startOfWeek(date), 7 * by) : shiftMonth(date, by)
    set({ date: next })
  }

  async function exportAs(type: 'csv' | 'xlsx' | 'pdf') {
    setExporting(type)
    try {
      await downloadFile(timesheetExportPath(filters, type))
    } catch (e) {
      toast.error(e)
    } finally {
      setExporting(null)
    }
  }

  const s = sheet.data
  const label = !s
    ? ''
    : period === 'daily'
      ? parseIsoDate(s.startDate).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
      : period === 'monthly'
        ? parseIsoDate(s.startDate).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
        : `${formatDayHeading(s.startDate).day} – ${formatDayHeading(s.endDate).day}`

  return (
    <>
      <PageHeader
        title="Timesheets"
        description={`Hours from check-in and check-out${s ? ` · times in ${s.timezone}` : ''}. Overtime is time worked beyond the shift length.`}
        actions={
          <>
            <Button variant="secondary" icon={<FileText className="size-4" />} loading={exporting === 'csv'} onClick={() => exportAs('csv')}>
              CSV
            </Button>
            <Button variant="secondary" icon={<FileSpreadsheet className="size-4" />} loading={exporting === 'xlsx'} onClick={() => exportAs('xlsx')}>
              Excel
            </Button>
            <Button icon={<Download className="size-4" />} loading={exporting === 'pdf'} onClick={() => exportAs('pdf')}>
              PDF
            </Button>
          </>
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1" role="tablist">
            {TABS.map((t) => (
              <button
                key={t.period}
                role="tab"
                aria-selected={period === t.period}
                onClick={() => set({ period: t.period === 'daily' ? '' : t.period })}
                className={clsx('rounded-md px-3 py-1.5 text-sm font-medium', period === t.period ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900')}
              >
                {t.label}
              </button>
            ))}
          </div>

          {period === 'custom' ? (
            <div className="flex items-center gap-2">
              <Input type="date" value={date} max={endDate} onChange={(e) => set({ date: e.target.value })} className="w-auto" aria-label="From" />
              <span className="text-slate-400">to</span>
              <Input type="date" value={endDate} min={date} onChange={(e) => set({ end: e.target.value })} className="w-auto" aria-label="To" />
            </div>
          ) : (
            <div className="flex items-center gap-2">
              <Button variant="secondary" size="sm" icon={<ChevronLeft className="size-4" />} onClick={() => step(-1)} aria-label="Previous" />
              <Button variant="secondary" size="sm" icon={<ChevronRight className="size-4" />} onClick={() => step(1)} aria-label="Next" />
              <span className="font-semibold text-slate-900">{label}</span>
              {date !== today && (
                <Button variant="ghost" size="sm" onClick={() => set({ date: '' })}>
                  Today
                </Button>
              )}
            </div>
          )}

          <div className="ml-auto">
            <Select value={departmentId} onChange={(e) => set({ departmentId: e.target.value })} className="w-auto" aria-label="Department">
              <option value="">All departments</option>
              {departments.data?.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <ErrorNotice error={sheet.error} />

        {s && (
          <div className="grid grid-cols-2 gap-3 border-b border-slate-100 p-4 sm:grid-cols-3 lg:grid-cols-6">
            <Stat label="Technicians" value={s.totals.technicians} />
            <Stat label="Shifts worked" value={s.totals.shifts} />
            <Stat label="Total hours" value={h(s.totals.workedHours)} />
            <Stat label="Regular" value={h(s.totals.regularHours)} />
            <Stat label="Overtime" value={h(s.totals.overtimeHours)} accent={s.totals.overtimeHours > 0 ? 'text-violet-700' : undefined} />
            <Stat label="Absences" value={s.totals.absences} accent={s.totals.absences > 0 ? 'text-red-600' : undefined} />
          </div>
        )}

        {sheet.isLoading ? (
          <LoadingBlock />
        ) : !s || (period === 'daily' ? !s.entries.length : !s.people.length) ? (
          <EmptyState icon={<SheetIcon className="size-6" />} title="No hours in this period" description="Hours appear here once technicians check in to their shifts." />
        ) : (
          <div className={clsx('overflow-x-auto transition-opacity', sheet.isFetching && 'opacity-70')}>
            {period === 'daily' ? <DailyTable sheet={s} /> : period === 'weekly' ? <WeeklyTable sheet={s} today={today} /> : <SummaryTable sheet={s} />}
          </div>
        )}
      </Card>
    </>
  )
}

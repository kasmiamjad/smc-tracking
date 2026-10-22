import clsx from 'clsx'
import { CheckCircle2, Search } from 'lucide-react'
import { useMemo, useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { addDays, formatMinutes, toIsoDate } from '../lib/format'
import { useAssignShift, useDepartments, useShifts, useUsers, type AssignResult } from '../lib/queries'
import { Button, DepartmentTag, ErrorNotice, Field, Input, Modal, Select } from './ui'

const SKIP_LABELS: Record<string, string> = { ALREADY_ASSIGNED: 'already on this shift' }

export function AssignShiftModal({ defaultFrom, onClose }: { defaultFrom: string; onClose: () => void }) {
  const [today] = useState(() => toIsoDate(new Date()))
  const shifts = useShifts()
  const departments = useDepartments()
  const technicians = useUsers({ role: 'TECHNICIAN', status: 'ACTIVE', blocked: '0', perPage: 200 })
  const assign = useAssignShift()

  const [shiftId, setShiftId] = useState('')
  const [mode, setMode] = useState<'people' | 'department'>('people')
  const [departmentId, setDepartmentId] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const [search, setSearch] = useState('')
  const start = defaultFrom < today ? today : defaultFrom
  const [startDate, setStartDate] = useState(start)
  const [endDate, setEndDate] = useState(addDays(start, 6))
  const [result, setResult] = useState<AssignResult | null>(null)

  const err = assign.error instanceof ApiError ? assign.error : null
  const activeShifts = shifts.data?.filter((s) => s.isActive) ?? []
  const people = useMemo(() => technicians.data?.data ?? [], [technicians.data])
  const nameOf = (id: string) => people.find((p) => p.id === id)?.fullName ?? 'Unknown'

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase()
    return q ? people.filter((p) => p.fullName.toLowerCase().includes(q) || p.phone?.includes(q)) : people
  }, [people, search])

  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  function submit(e: FormEvent) {
    e.preventDefault()
    assign.mutate(
      { shiftId, startDate, endDate, ...(mode === 'people' ? { userIds: selected } : { departmentId }) },
      { onSuccess: setResult },
    )
  }

  if (result) {
    return (
      <Modal open onClose={onClose} title="Shifts assigned" footer={<Button onClick={onClose}>Done</Button>}>
        <div className="flex items-start gap-3">
          <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-emerald-600" />
          <div>
            <p className="font-medium text-slate-900">
              {result.created} {result.created === 1 ? 'shift' : 'shifts'} added for {result.technicians} {result.technicians === 1 ? 'technician' : 'technicians'}.
            </p>
            {result.skipped.length > 0 && <p className="mt-1 text-sm text-slate-500">{result.skipped.length} skipped because they would double-book someone:</p>}
          </div>
        </div>
        {result.skipped.length > 0 && (
          <ul className="mt-3 max-h-56 space-y-1 overflow-y-auto rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            {result.skipped.map((s) => (
              <li key={`${s.userId}-${s.date}`}>
                {nameOf(s.userId)} · {s.date} · {SKIP_LABELS[s.reason] ?? `overlaps ${s.reason.replace(/^OVERLAPS:/, '')}`}
              </li>
            ))}
          </ul>
        )}
      </Modal>
    )
  }

  const canSubmit = shiftId && (mode === 'people' ? selected.length > 0 : departmentId)

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title="Assign shift"
      description="Creates a shift for each matching day. Anyone already booked at that time is skipped."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="assign-form" loading={assign.isPending} disabled={!canSubmit}>
            Assign
          </Button>
        </>
      }
    >
      <form id="assign-form" onSubmit={submit} className="space-y-4">
        {assign.error && !Object.keys(err?.fieldErrors ?? {}).length && <ErrorNotice error={assign.error} />}

        <Field label="Shift" error={err?.field('shiftId')}>
          {(id) => (
            <Select id={id} required value={shiftId} onChange={(e) => setShiftId(e.target.value)}>
              <option value="">Choose a shift</option>
              {activeShifts.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} · {s.startTime}–{s.endTime} ({formatMinutes(s.lengthMinutes)})
                </option>
              ))}
            </Select>
          )}
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="From" error={err?.field('startDate')}>
            {(id) => <Input id={id} type="date" required min={today} value={startDate} onChange={(e) => setStartDate(e.target.value)} />}
          </Field>
          <Field label="To" error={err?.field('endDate')}>
            {(id) => <Input id={id} type="date" required min={startDate} value={endDate} onChange={(e) => setEndDate(e.target.value)} />}
          </Field>
        </div>

        <div>
          <div className="mb-2 grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
            {(['people', 'department'] as const).map((m) => (
              <button
                key={m}
                type="button"
                onClick={() => setMode(m)}
                className={clsx('rounded-md py-1.5 text-sm font-medium', mode === m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600')}
              >
                {m === 'people' ? 'Pick technicians' : 'Whole department'}
              </button>
            ))}
          </div>

          {mode === 'department' ? (
            <Select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} aria-label="Department">
              <option value="">Choose a department</option>
              {departments.data?.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          ) : (
            <div className="rounded-lg ring-1 ring-slate-200">
              <div className="relative border-b border-slate-100 p-2">
                <Search className="pointer-events-none absolute top-1/2 left-5 size-4 -translate-y-1/2 text-slate-400" />
                <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search technicians" className="pl-9" aria-label="Search technicians" />
              </div>
              <ul className="max-h-56 overflow-y-auto">
                {visible.map((p) => (
                  <li key={p.id}>
                    <label className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50">
                      <input type="checkbox" checked={selected.includes(p.id)} onChange={() => toggle(p.id)} className="size-4 rounded border-slate-300 text-brand-600" />
                      <span className="flex-1 text-sm text-slate-900">{p.fullName}</span>
                      <DepartmentTag department={p.department} />
                    </label>
                  </li>
                ))}
                {!visible.length && <li className="px-3 py-4 text-center text-sm text-slate-500">No active technicians found.</li>}
              </ul>
              <div className="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">{selected.length} selected</div>
            </div>
          )}
        </div>
      </form>
    </Modal>
  )
}

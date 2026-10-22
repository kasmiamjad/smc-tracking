import clsx from 'clsx'
import { Clock, MapPinned, Moon, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useToast } from '../components/toast'
import { Badge, Button, Card, EmptyState, ErrorNotice, Field, Input, LoadingBlock, Modal, PageHeader } from '../components/ui'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { formatMinutes } from '../lib/format'
import { useDeleteShift, useSaveShift, useShifts } from '../lib/queries'
import { WEEKDAYS, type Shift } from '../lib/types'

function DayPicker({ value, onChange }: { value: number[]; onChange: (days: number[]) => void }) {
  const toggle = (iso: number) => onChange(value.includes(iso) ? value.filter((d) => d !== iso) : [...value, iso].sort())
  return (
    <div className="flex flex-wrap gap-1.5">
      {WEEKDAYS.map((d) => (
        <button
          key={d.iso}
          type="button"
          aria-pressed={value.includes(d.iso)}
          onClick={() => toggle(d.iso)}
          className={clsx(
            'w-12 rounded-lg py-1.5 text-sm font-medium ring-1 ring-inset transition-colors',
            value.includes(d.iso) ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50',
          )}
        >
          {d.short}
        </button>
      ))}
    </div>
  )
}

function ShiftModal({ shift, onClose }: { shift: Shift | null; onClose: () => void }) {
  const [name, setName] = useState(shift?.name ?? '')
  const [startTime, setStartTime] = useState(shift?.startTime ?? '08:00')
  const [endTime, setEndTime] = useState(shift?.endTime ?? '16:00')
  const [days, setDays] = useState<number[]>(shift?.daysOfWeek ?? [1, 2, 3, 4, 5])
  const [isActive, setIsActive] = useState(shift?.isActive ?? true)
  const save = useSaveShift()
  const toast = useToast()
  const err = save.error instanceof ApiError ? save.error : null
  const overnight = endTime <= startTime

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      { id: shift?.id, name: name.trim(), startTime, endTime, daysOfWeek: days, isActive },
      {
        onSuccess: () => {
          toast.success(shift ? 'Shift updated.' : 'Shift created.')
          onClose()
        },
      },
    )
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={shift ? 'Edit shift' : 'New shift'}
      description={shift ? 'Changes apply to shifts assigned from now on. Already assigned shifts keep their times.' : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="shift-form" loading={save.isPending} disabled={!days.length}>
            Save
          </Button>
        </>
      }
    >
      <form id="shift-form" onSubmit={submit} className="space-y-4">
        {save.error && !Object.keys(err?.fieldErrors ?? {}).length && <ErrorNotice error={save.error} />}
        <Field label="Name" error={err?.field('name')}>
          {(id) => <Input id={id} required maxLength={100} placeholder="e.g. Shift A" value={name} onChange={(e) => setName(e.target.value)} autoFocus />}
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Starts" error={err?.field('startTime')}>
            {(id) => <Input id={id} type="time" required value={startTime} onChange={(e) => setStartTime(e.target.value)} />}
          </Field>
          <Field label="Ends" error={err?.field('endTime')} hint={overnight && endTime !== startTime ? 'Ends the next day' : undefined}>
            {(id) => <Input id={id} type="time" required value={endTime} onChange={(e) => setEndTime(e.target.value)} />}
          </Field>
        </div>
        <Field label="Runs on" error={err?.field('daysOfWeek') ?? (!days.length ? 'Pick at least one day.' : undefined)}>
          {() => <DayPicker value={days} onChange={setDays} />}
        </Field>
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="size-4 rounded border-slate-300 text-brand-600" />
          Active. Inactive shifts can't be assigned but keep their history.
        </label>
      </form>
    </Modal>
  )
}

export function ShiftsPage() {
  const { isAdmin } = useAuth()
  const shifts = useShifts()
  const remove = useDeleteShift()
  const toast = useToast()
  const [editing, setEditing] = useState<Shift | null | undefined>(undefined)
  const timezone = shifts.data?.[0]?.timezone

  return (
    <>
      <PageHeader
        title="Shifts"
        description={`Shift templates you assign technicians to${timezone ? ` · times in ${timezone}` : ''}. Overtime is time worked beyond the shift length.`}
        actions={
          isAdmin && (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing(null)}>
              New shift
            </Button>
          )
        }
      />

      <ErrorNotice error={shifts.error} />

      {shifts.isLoading ? (
        <LoadingBlock />
      ) : !shifts.data?.length ? (
        <Card>
          <EmptyState icon={<Clock className="size-6" />} title="No shifts yet" description="Create a shift such as 08:00–16:00, then assign technicians to it on the roster." />
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {shifts.data.map((s) => (
            <Card key={s.id} className={clsx('p-5', !s.isActive && 'opacity-60')}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <h2 className="font-semibold text-slate-900">{s.name}</h2>
                    {!s.isActive && <Badge>Inactive</Badge>}
                  </div>
                  <p className="mt-1 flex items-center gap-1.5 text-2xl font-semibold tabular-nums text-slate-900">
                    {s.startTime}
                    <span className="text-slate-300">–</span>
                    {s.endTime}
                    {s.crossesMidnight && <Moon className="size-4 text-slate-400" aria-label="Ends next day" />}
                  </p>
                  <p className="text-sm text-slate-500">{formatMinutes(s.lengthMinutes)} shift</p>
                </div>
                {isAdmin && (
                  <div className="flex gap-1">
                    <Button variant="ghost" size="sm" icon={<Pencil className="size-4" />} onClick={() => setEditing(s)} title="Edit">
                      <span className="sr-only">Edit</span>
                    </Button>
                    {!s.assignmentsCount && (
                      <Button
                        variant="ghost"
                        size="sm"
                        icon={<Trash2 className="size-4 text-slate-400" />}
                        title="Delete"
                        onClick={() => confirm(`Delete ${s.name}?`) && remove.mutate(s.id, { onSuccess: () => toast.success('Shift deleted.'), onError: toast.error })}
                      >
                        <span className="sr-only">Delete</span>
                      </Button>
                    )}
                  </div>
                )}
              </div>

              <div className="mt-4 flex gap-1">
                {WEEKDAYS.map((d) => (
                  <span
                    key={d.iso}
                    className={clsx(
                      'flex-1 rounded py-1 text-center text-xs font-medium',
                      s.daysOfWeek.includes(d.iso) ? 'bg-brand-50 text-brand-700' : 'bg-slate-50 text-slate-300',
                    )}
                  >
                    {d.short}
                  </span>
                ))}
              </div>

              <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                <span className="inline-flex items-center gap-1">
                  <MapPinned className="size-3.5" />
                  {s.geofence ? `Zone with ${s.geofence.length} points` : 'Any location'}
                </span>
                <span>{s.assignmentsCount ?? 0} assigned</span>
              </div>
            </Card>
          ))}
        </div>
      )}

      {editing !== undefined && <ShiftModal shift={editing} onClose={() => setEditing(undefined)} />}
    </>
  )
}

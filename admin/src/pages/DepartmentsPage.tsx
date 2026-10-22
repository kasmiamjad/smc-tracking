import { Building2, Pencil, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useToast } from '../components/toast'
import { Button, Card, EmptyState, ErrorNotice, Field, Input, LoadingBlock, Modal, PageHeader } from '../components/ui'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useDeleteDepartment, useDepartments, useSaveDepartment } from '../lib/queries'
import type { Department } from '../lib/types'

const SWATCHES = ['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316', '#64748B']

function DepartmentModal({ department, open, onClose }: { department: Department | null; open: boolean; onClose: () => void }) {
  const [name, setName] = useState('')
  const [colorHex, setColorHex] = useState(SWATCHES[0]!)
  const save = useSaveDepartment()
  const toast = useToast()
  const err = save.error instanceof ApiError ? save.error : null

  useEffect(() => {
    if (!open) return
    setName(department?.name ?? '')
    setColorHex(department?.colorHex ?? SWATCHES[0]!)
    save.reset()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, department])

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      { id: department?.id, name: name.trim(), colorHex },
      {
        onSuccess: () => {
          toast.success(department ? 'Department updated.' : 'Department created.')
          onClose()
        },
      },
    )
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={department ? 'Edit department' : 'New department'}
      description="The color is used for this team's pins on the live map."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="department-form" loading={save.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form id="department-form" onSubmit={submit} className="space-y-4">
        {save.error && !Object.keys(err?.fieldErrors ?? {}).length && <ErrorNotice error={save.error} />}
        <Field label="Name" error={err?.field('name')}>
          {(id) => <Input id={id} required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} autoFocus />}
        </Field>
        <Field label="Map color" error={err?.field('colorHex')}>
          {(id) => (
            <div className="space-y-3">
              <div className="flex flex-wrap gap-2">
                {SWATCHES.map((c) => (
                  <button
                    key={c}
                    type="button"
                    onClick={() => setColorHex(c)}
                    className={`size-8 rounded-full ring-offset-2 transition ${colorHex.toUpperCase() === c ? 'ring-2 ring-slate-900' : 'hover:scale-110'}`}
                    style={{ backgroundColor: c }}
                    aria-label={`Color ${c}`}
                  />
                ))}
              </div>
              <div className="flex items-center gap-2">
                <input type="color" value={colorHex} onChange={(e) => setColorHex(e.target.value.toUpperCase())} className="size-9 cursor-pointer rounded border-0 bg-transparent p-0" aria-label="Custom color" />
                <Input id={id} value={colorHex} onChange={(e) => setColorHex(e.target.value)} className="w-32 font-mono" />
              </div>
            </div>
          )}
        </Field>
      </form>
    </Modal>
  )
}

export function DepartmentsPage() {
  const { isAdmin } = useAuth()
  const departments = useDepartments()
  const remove = useDeleteDepartment()
  const toast = useToast()
  const [editing, setEditing] = useState<Department | null>(null)
  const [open, setOpen] = useState(false)

  function edit(d: Department | null) {
    setEditing(d)
    setOpen(true)
  }

  return (
    <>
      <PageHeader
        title="Departments"
        description="Teams and the color their technicians show on the map"
        actions={
          isAdmin && (
            <Button icon={<Plus className="size-4" />} onClick={() => edit(null)}>
              New department
            </Button>
          )
        }
      />

      <ErrorNotice error={departments.error} />

      {departments.isLoading ? (
        <LoadingBlock />
      ) : !departments.data?.length ? (
        <Card>
          <EmptyState icon={<Building2 className="size-6" />} title="No departments yet" description="Create one to group technicians and color their map pins." />
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {departments.data.map((d) => (
            <Card key={d.id} className="flex items-center gap-4 p-5">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-full ring-4" style={{ backgroundColor: d.colorHex, ['--tw-ring-color' as string]: d.colorHex + '33' }}>
                <span className="size-3 rounded-full bg-white" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-slate-900">{d.name}</p>
                <Link to={`/users?departmentId=${d.id}`} className="text-sm text-slate-500 hover:text-brand-700">
                  {d.usersCount ?? 0} {d.usersCount === 1 ? 'member' : 'members'}
                </Link>
              </div>
              {isAdmin && (
                <div className="flex gap-1">
                  <Button variant="ghost" size="sm" icon={<Pencil className="size-4" />} onClick={() => edit(d)} title="Edit">
                    <span className="sr-only">Edit</span>
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    icon={<Trash2 className="size-4 text-slate-400" />}
                    title="Delete"
                    onClick={() =>
                      confirm(`Delete ${d.name}?${d.usersCount ? ` Its ${d.usersCount} members become unassigned.` : ''}`) &&
                      remove.mutate(d.id, { onSuccess: () => toast.success('Department deleted.'), onError: toast.error })
                    }
                  >
                    <span className="sr-only">Delete</span>
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      <DepartmentModal department={editing} open={open} onClose={() => setOpen(false)} />
    </>
  )
}

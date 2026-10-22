import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { ApiError } from '../lib/api'
import { useCreateUser, useDepartments } from '../lib/queries'
import type { Role } from '../lib/types'
import { TemporaryPinDialog } from './dialogs'
import { useToast } from './toast'
import { Button, ErrorNotice, Field, Input, Modal, Select } from './ui'

const EMPTY = { fullName: '', phone: '', pin: '', email: '', password: '', departmentId: '' }

export function CreateUserModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [role, setRole] = useState<Role>('TECHNICIAN')
  const [form, setForm] = useState(EMPTY)
  const [created, setCreated] = useState<{ id: string; name: string; pin: string } | null>(null)
  const departments = useDepartments()
  const create = useCreateUser()
  const toast = useToast()
  const navigate = useNavigate()

  useEffect(() => {
    if (open) {
      setForm(EMPTY)
      setRole('TECHNICIAN')
      create.reset()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const isTech = role === 'TECHNICIAN'
  const err = create.error instanceof ApiError ? create.error : null
  const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  function submit(e: FormEvent) {
    e.preventDefault()
    create.mutate(
      {
        role,
        fullName: form.fullName.trim(),
        departmentId: form.departmentId || null,
        ...(isTech ? { phone: form.phone.trim(), pin: form.pin || undefined } : { email: form.email.trim(), password: form.password }),
      },
      {
        onSuccess: (res) => {
          onClose()
          if (res.temporaryPin) {
            setCreated({ id: res.data.id, name: res.data.fullName, pin: res.temporaryPin })
          } else {
            toast.success(`${res.data.fullName} created.`)
            navigate(`/users/${res.data.id}`)
          }
        },
      },
    )
  }

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title="Add user"
        description="Accounts created here are active immediately."
        footer={
          <>
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" form="create-user" loading={create.isPending}>
              Create {isTech ? 'technician' : role === 'ADMIN' ? 'admin' : 'dispatcher'}
            </Button>
          </>
        }
      >
        <form id="create-user" onSubmit={submit} className="space-y-4">
          {create.error && !Object.keys(err?.fieldErrors ?? {}).length && <ErrorNotice error={create.error} />}

          <div className="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1" role="radiogroup" aria-label="Role">
            {(['TECHNICIAN', 'DISPATCHER', 'ADMIN'] as Role[]).map((r) => (
              <button
                key={r}
                type="button"
                role="radio"
                aria-checked={role === r}
                onClick={() => setRole(r)}
                className={`rounded-md px-2 py-1.5 text-sm font-medium transition-colors ${role === r ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
              >
                {r === 'TECHNICIAN' ? 'Technician' : r === 'DISPATCHER' ? 'Dispatcher' : 'Admin'}
              </button>
            ))}
          </div>

          <Field label="Full name" error={err?.field('fullName')}>
            {(id) => <Input id={id} required value={form.fullName} onChange={set('fullName')} autoFocus />}
          </Field>

          {isTech ? (
            <>
              <Field label="Phone number" error={err?.field('phone')} hint="Include the country code, e.g. +966501234567">
                {(id) => <Input id={id} type="tel" required placeholder="+966" value={form.phone} onChange={set('phone')} />}
              </Field>
              <Field label="Temporary PIN (optional)" error={err?.field('pin')} hint="Leave empty to generate one. They must change it at first sign-in.">
                {(id) => <Input id={id} inputMode="numeric" maxLength={4} pattern="\d{4}" value={form.pin} onChange={set('pin')} />}
              </Field>
            </>
          ) : (
            <>
              <Field label="Email" error={err?.field('email')}>
                {(id) => <Input id={id} type="email" required value={form.email} onChange={set('email')} />}
              </Field>
              <Field label="Password" error={err?.field('password')} hint="At least 10 characters, with letters and numbers.">
                {(id) => <Input id={id} type="password" autoComplete="new-password" required minLength={10} value={form.password} onChange={set('password')} />}
              </Field>
            </>
          )}

          <Field label="Department" error={err?.field('departmentId')}>
            {(id) => (
              <Select id={id} value={form.departmentId} onChange={set('departmentId')}>
                <option value="">Unassigned</option>
                {departments.data?.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </form>
      </Modal>

      <TemporaryPinDialog
        pin={created?.pin ?? null}
        name={created?.name ?? ''}
        onClose={() => {
          const id = created?.id
          setCreated(null)
          if (id) navigate(`/users/${id}`)
        }}
      />
    </>
  )
}

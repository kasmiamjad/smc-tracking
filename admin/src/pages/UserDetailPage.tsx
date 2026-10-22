import { ArrowLeft, Ban, Check, KeyRound, LockOpen, Pencil, ShieldCheck, X } from 'lucide-react'
import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { DocumentList } from '../components/DocumentList'
import { ReasonDialog, TemporaryPinDialog } from '../components/dialogs'
import { useToast } from '../components/toast'
import { Avatar, Button, Card, DepartmentTag, ErrorNotice, Field, Input, LoadingBlock, Modal, RoleBadge, Select, UserStateBadge } from '../components/ui'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { formatDateTime, formatRelative } from '../lib/format'
import { useApproval, useBlock, useDepartments, useResetPin, useUnlock, useUpdateUser, useUser } from '../lib/queries'
import type { User } from '../lib/types'

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
      <dd className="mt-1 text-sm text-slate-900">{children}</dd>
    </div>
  )
}

function Section({ title, children, aside }: { title: string; children: ReactNode; aside?: ReactNode }) {
  return (
    <Card>
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <h2 className="font-semibold text-slate-900">{title}</h2>
        {aside}
      </div>
      <div className="p-5">{children}</div>
    </Card>
  )
}

function EditUserModal({ user, open, onClose }: { user: User; open: boolean; onClose: () => void }) {
  const [fullName, setFullName] = useState(user.fullName)
  const [phone, setPhone] = useState(user.phone ?? '')
  const [departmentId, setDepartmentId] = useState(user.departmentId ?? '')
  const departments = useDepartments()
  const update = useUpdateUser()
  const toast = useToast()
  const err = update.error instanceof ApiError ? update.error : null

  function submit(e: FormEvent) {
    e.preventDefault()
    update.mutate(
      { id: user.id, fullName: fullName.trim(), departmentId: departmentId || null, ...(user.role === 'TECHNICIAN' ? { phone: phone.trim() } : {}) },
      {
        onSuccess: () => {
          toast.success('Details saved.')
          onClose()
        },
      },
    )
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Edit details"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="edit-user" loading={update.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form id="edit-user" onSubmit={submit} className="space-y-4">
        {update.error && !Object.keys(err?.fieldErrors ?? {}).length && <ErrorNotice error={update.error} />}
        <Field label="Full name" error={err?.field('fullName')}>
          {(id) => <Input id={id} required value={fullName} onChange={(e) => setFullName(e.target.value)} />}
        </Field>
        {user.role === 'TECHNICIAN' && (
          <Field label="Phone number" error={err?.field('phone')} hint="Changing it changes the number they sign in with.">
            {(id) => <Input id={id} type="tel" required value={phone} onChange={(e) => setPhone(e.target.value)} />}
          </Field>
        )}
        <Field label="Department" error={err?.field('departmentId')}>
          {(id) => (
            <Select id={id} value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
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
  )
}

export function UserDetailPage() {
  const { id = '' } = useParams()
  const { user: me, isAdmin } = useAuth()
  const query = useUser(id)
  const approval = useApproval()
  const block = useBlock()
  const resetPin = useResetPin()
  const unlock = useUnlock()
  const toast = useToast()

  const [editing, setEditing] = useState(false)
  const [dialog, setDialog] = useState<'reject' | 'block' | null>(null)
  const [tempPin, setTempPin] = useState<string | null>(null)

  if (query.isLoading) return <LoadingBlock />
  if (query.error || !query.data)
    return (
      <div className="space-y-4">
        <BackLink />
        <ErrorNotice error={query.error ?? new Error('User not found.')} />
      </div>
    )

  const user = query.data
  const isSelf = me?.id === user.id
  const isTech = user.role === 'TECHNICIAN'

  return (
    <>
      <BackLink />

      {/* Header */}
      <Card className="mt-4 p-5">
        <div className="flex flex-wrap items-center gap-4">
          <Avatar name={user.fullName} color={user.department?.colorHex} size="lg" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-semibold text-slate-900">{user.fullName}</h1>
              <RoleBadge role={user.role} />
              <UserStateBadge user={user} />
            </div>
            <p className="mt-1 text-sm text-slate-500">
              {user.phone ?? user.email} · Last sign-in {formatRelative(user.lastLoginAt, true)}
            </p>
          </div>

          {isAdmin && (
            <div className="flex flex-wrap gap-2">
              {user.status === 'PENDING_APPROVAL' && (
                <>
                  <Button variant="secondary" icon={<X className="size-4" />} onClick={() => setDialog('reject')}>
                    Reject
                  </Button>
                  <Button
                    variant="success"
                    icon={<Check className="size-4" />}
                    loading={approval.isPending}
                    onClick={() => approval.mutate({ id: user.id, status: 'ACTIVE' }, { onSuccess: () => toast.success('Approved.'), onError: toast.error })}
                  >
                    Approve
                  </Button>
                </>
              )}
              {user.status === 'REJECTED' && (
                <Button
                  variant="success"
                  icon={<Check className="size-4" />}
                  loading={approval.isPending}
                  onClick={() => approval.mutate({ id: user.id, status: 'ACTIVE' }, { onSuccess: () => toast.success('Approved.'), onError: toast.error })}
                >
                  Approve instead
                </Button>
              )}
              <Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>
                Edit
              </Button>
            </div>
          )}
        </div>

        {user.isBlocked && (
          <div className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-inset ring-red-200">
            Blocked {formatDateTime(user.blockedAt)}
            {user.blockReason ? `: ${user.blockReason.replace(/[.!?]?$/, '.')}` : '.'} They can't sign in and all their sessions were ended.
          </div>
        )}
        {user.isLocked && !user.isBlocked && (
          <div className="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200">
            Locked after too many wrong {isTech ? 'PINs' : 'passwords'}. Unlock the account or reset the PIN below.
          </div>
        )}
        {user.status === 'REJECTED' && user.rejectionReason && (
          <div className="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-200">
            Registration rejected: {user.rejectionReason}
          </div>
        )}
      </Card>

      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <Section title="Details">
            <dl className="grid gap-5 sm:grid-cols-2">
              <Detail label="Phone">{user.phone ?? '—'}</Detail>
              <Detail label="Email">{user.email ?? '—'}</Detail>
              <Detail label="Department">
                <DepartmentTag department={user.department} />
              </Detail>
              <Detail label="Registered">{formatDateTime(user.createdAt)}</Detail>
              <Detail label="Approved">{formatDateTime(user.approvedAt)}</Detail>
              {isTech && <Detail label="PIN">{user.mustChangePin ? 'Temporary, must be changed at next sign-in' : 'Set by technician'}</Detail>}
            </dl>
          </Section>

          {isTech && (
            <Section title="Documents">
              <DocumentList userId={user.id} documents={user.documents ?? []} />
            </Section>
          )}
        </div>

        {isAdmin && !isSelf && (
          <Section title="Access">
            <div className="space-y-4">
              {user.isLocked && (
                <ActionRow
                  icon={<LockOpen className="size-5 text-amber-600" />}
                  title="Unlock account"
                  text="Clears failed attempts. Their current PIN stays the same."
                  action={
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={unlock.isPending}
                      onClick={() => unlock.mutate(user.id, { onSuccess: () => toast.success('Account unlocked.'), onError: toast.error })}
                    >
                      Unlock
                    </Button>
                  }
                />
              )}

              {isTech && (
                <ActionRow
                  icon={<KeyRound className="size-5 text-brand-600" />}
                  title="Reset PIN"
                  text="Issues a temporary PIN and signs them out everywhere."
                  action={
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={resetPin.isPending}
                      onClick={() =>
                        confirm(`Reset ${user.fullName}'s PIN? Their current sessions will end.`) &&
                        resetPin.mutate(user.id, { onSuccess: (res) => setTempPin(res.temporaryPin), onError: toast.error })
                      }
                    >
                      Reset
                    </Button>
                  }
                />
              )}

              {user.isBlocked ? (
                <ActionRow
                  icon={<ShieldCheck className="size-5 text-emerald-600" />}
                  title="Unblock"
                  text="They can sign in again with their existing PIN."
                  action={
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={block.isPending}
                      onClick={() => block.mutate({ id: user.id, isBlocked: false }, { onSuccess: () => toast.success('Unblocked.'), onError: toast.error })}
                    >
                      Unblock
                    </Button>
                  }
                />
              ) : (
                <ActionRow
                  icon={<Ban className="size-5 text-red-600" />}
                  title={isTech ? 'Block phone number' : 'Block account'}
                  text="Signs them out immediately and refuses future sign-ins."
                  action={
                    <Button variant="danger" size="sm" onClick={() => setDialog('block')}>
                      Block
                    </Button>
                  }
                />
              )}
            </div>
          </Section>
        )}
      </div>

      <EditUserModal key={String(editing)} user={user} open={editing} onClose={() => setEditing(false)} />

      <ReasonDialog
        open={dialog === 'reject'}
        onClose={() => setDialog(null)}
        title={`Reject ${user.fullName}?`}
        confirmLabel="Reject registration"
        reasonRequired
        pending={approval.isPending}
        error={approval.error}
        onConfirm={(reason) =>
          approval.mutate({ id: user.id, status: 'REJECTED', reason }, { onSuccess: () => (setDialog(null), toast.success('Registration rejected.')) })
        }
      />

      <ReasonDialog
        open={dialog === 'block'}
        onClose={() => setDialog(null)}
        title={`Block ${user.fullName}?`}
        description="All their sessions end now and they can't sign in until you unblock them."
        confirmLabel="Block"
        pending={block.isPending}
        error={block.error}
        onConfirm={(reason) =>
          block.mutate({ id: user.id, isBlocked: true, reason: reason || undefined }, { onSuccess: () => (setDialog(null), toast.success(`${user.fullName} blocked.`)) })
        }
      />

      <TemporaryPinDialog pin={tempPin} name={user.fullName} onClose={() => setTempPin(null)} />
    </>
  )
}

function ActionRow({ icon, title, text, action }: { icon: ReactNode; title: string; text: string; action: ReactNode }) {
  return (
    <div className="flex items-start gap-3">
      <div className="mt-0.5">{icon}</div>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-slate-900">{title}</p>
        <p className="text-xs text-slate-500">{text}</p>
      </div>
      {action}
    </div>
  )
}

function BackLink() {
  return (
    <Link to="/users" className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-800">
      <ArrowLeft className="size-4" />
      All users
    </Link>
  )
}

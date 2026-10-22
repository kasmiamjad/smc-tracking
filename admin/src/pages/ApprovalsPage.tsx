import { Check, Inbox, Phone, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { DocumentList } from '../components/DocumentList'
import { ReasonDialog } from '../components/dialogs'
import { useToast } from '../components/toast'
import { Avatar, Button, Card, DepartmentTag, EmptyState, ErrorNotice, LoadingBlock, PageHeader } from '../components/ui'
import { useAuth } from '../lib/auth'
import { formatDateTime, formatRelative } from '../lib/format'
import { useApproval, usePendingUsers } from '../lib/queries'
import type { User } from '../lib/types'

export function ApprovalsPage() {
  const { isAdmin } = useAuth()
  const pending = usePendingUsers()
  const approval = useApproval()
  const toast = useToast()
  const [rejecting, setRejecting] = useState<User | null>(null)

  function approve(user: User) {
    approval.mutate(
      { id: user.id, status: 'ACTIVE' },
      { onSuccess: () => toast.success(`${user.fullName} approved. They can sign in now.`), onError: toast.error },
    )
  }

  return (
    <>
      <PageHeader
        title="Approvals"
        description="Technicians who registered from the mobile app. Documents are optional and don't block approval."
      />

      <ErrorNotice error={pending.error} />

      {pending.isLoading ? (
        <LoadingBlock />
      ) : !pending.data?.length ? (
        <Card>
          <EmptyState icon={<Inbox className="size-6" />} title="All caught up" description="New registrations will appear here." />
        </Card>
      ) : (
        <div className="space-y-4">
          {pending.data.map((user) => (
            <Card key={user.id} className="p-5">
              <div className="flex flex-wrap items-start gap-4">
                <Avatar name={user.fullName} color={user.department?.colorHex} size="lg" />
                <div className="min-w-0 flex-1">
                  <Link to={`/users/${user.id}`} className="text-base font-semibold text-slate-900 hover:text-brand-700">
                    {user.fullName}
                  </Link>
                  <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                    <a href={`tel:${user.phone}`} className="inline-flex items-center gap-1.5 hover:text-brand-700">
                      <Phone className="size-4 text-slate-400" />
                      {user.phone}
                    </a>
                    <DepartmentTag department={user.department} />
                    <span className="text-slate-400" title={formatDateTime(user.createdAt)}>
                      Registered {formatRelative(user.createdAt, true)}
                    </span>
                  </div>
                </div>
                {isAdmin && (
                  <div className="flex gap-2">
                    <Button variant="secondary" icon={<X className="size-4" />} onClick={() => setRejecting(user)}>
                      Reject
                    </Button>
                    <Button
                      variant="success"
                      icon={<Check className="size-4" />}
                      loading={approval.isPending && approval.variables?.id === user.id && approval.variables.status === 'ACTIVE'}
                      onClick={() => approve(user)}
                    >
                      Approve
                    </Button>
                  </div>
                )}
              </div>

              <div className="mt-4 border-t border-slate-100 pt-4">
                <DocumentList userId={user.id} documents={user.documents ?? []} allowUpload={false} />
              </div>
            </Card>
          ))}
        </div>
      )}

      <ReasonDialog
        open={rejecting !== null}
        onClose={() => setRejecting(null)}
        title={`Reject ${rejecting?.fullName ?? ''}?`}
        description="They won't be able to sign in. You can approve them later from their profile."
        confirmLabel="Reject registration"
        reasonRequired
        pending={approval.isPending}
        error={approval.error}
        onConfirm={(reason) =>
          rejecting &&
          approval.mutate(
            { id: rejecting.id, status: 'REJECTED', reason },
            {
              onSuccess: () => {
                toast.success(`${rejecting.fullName} rejected.`)
                setRejecting(null)
              },
            },
          )
        }
      />
    </>
  )
}

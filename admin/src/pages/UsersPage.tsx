import { ChevronLeft, ChevronRight, Search, UserPlus, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { CreateUserModal } from '../components/CreateUserModal'
import { Avatar, Button, Card, DepartmentTag, EmptyState, ErrorNotice, Input, LoadingBlock, PageHeader, RoleBadge, Select, UserStateBadge } from '../components/ui'
import { useAuth } from '../lib/auth'
import { formatRelative } from '../lib/format'
import { useDepartments, useUsers, type UserFilters } from '../lib/queries'
import type { Role, UserStatus } from '../lib/types'

const PER_PAGE = 25

export function UsersPage() {
  const { isAdmin } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)
  const departments = useDepartments()

  const filters: UserFilters = {
    search: params.get('search') ?? '',
    status: (params.get('status') ?? '') as UserStatus | '',
    role: (params.get('role') ?? '') as Role | '',
    departmentId: params.get('departmentId') ?? '',
    blocked: (params.get('blocked') ?? '') as UserFilters['blocked'],
    page: Number(params.get('page') ?? 1),
    perPage: PER_PAGE,
  }
  const users = useUsers(filters)

  function setFilter(key: string, value: string) {
    setParams((p) => {
      if (value) p.set(key, value)
      else p.delete(key)
      if (key !== 'page') p.delete('page')
      return p
    })
  }

  // Debounce the search box into the URL.
  useEffect(() => {
    const t = setTimeout(() => {
      if (search !== (params.get('search') ?? '')) setFilter('search', search.trim())
    }, 300)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const meta = users.data?.meta
  const hasFilters = ['search', 'status', 'role', 'departmentId', 'blocked'].some((k) => params.get(k))

  return (
    <>
      <PageHeader
        title="Users"
        description="Technicians, dispatchers and admins"
        actions={
          isAdmin && (
            <Button icon={<UserPlus className="size-4" />} onClick={() => setCreating(true)}>
              Add user
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap gap-3 border-b border-slate-100 p-4">
          <div className="relative min-w-56 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <Input placeholder="Name, phone or email" value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" aria-label="Search" />
          </div>
          <Select value={filters.role} onChange={(e) => setFilter('role', e.target.value)} aria-label="Role" className="w-auto">
            <option value="">All roles</option>
            <option value="TECHNICIAN">Technicians</option>
            <option value="DISPATCHER">Dispatchers</option>
            <option value="ADMIN">Admins</option>
          </Select>
          <Select value={filters.status} onChange={(e) => setFilter('status', e.target.value)} aria-label="Status" className="w-auto">
            <option value="">Any status</option>
            <option value="ACTIVE">Active</option>
            <option value="PENDING_APPROVAL">Pending approval</option>
            <option value="REJECTED">Rejected</option>
          </Select>
          <Select value={filters.departmentId} onChange={(e) => setFilter('departmentId', e.target.value)} aria-label="Department" className="w-auto">
            <option value="">All departments</option>
            {departments.data?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
          <Select value={filters.blocked} onChange={(e) => setFilter('blocked', e.target.value)} aria-label="Access" className="w-auto">
            <option value="">Any access</option>
            <option value="0">Not blocked</option>
            <option value="1">Blocked only</option>
          </Select>
        </div>

        <ErrorNotice error={users.error} />

        {users.isLoading ? (
          <LoadingBlock />
        ) : !users.data?.data.length ? (
          <EmptyState
            icon={<Users className="size-6" />}
            title={hasFilters ? 'No users match these filters' : 'No users yet'}
            description={hasFilters ? 'Try clearing a filter or the search.' : 'Technicians appear here after they register or you add them.'}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-100 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-medium tracking-wide text-slate-500 uppercase">
                <tr>
                  <th className="px-4 py-3">Name</th>
                  <th className="px-4 py-3">Contact</th>
                  <th className="px-4 py-3">Department</th>
                  <th className="px-4 py-3">Role</th>
                  <th className="px-4 py-3">State</th>
                  <th className="px-4 py-3">Last sign-in</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.data.data.map((u) => (
                  <tr key={u.id} onClick={() => navigate(`/users/${u.id}`)} className="cursor-pointer hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        <Avatar name={u.fullName} color={u.department?.colorHex} />
                        <Link to={`/users/${u.id}`} className="font-medium whitespace-nowrap text-slate-900 hover:text-brand-700" onClick={(e) => e.stopPropagation()}>
                          {u.fullName}
                        </Link>
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-slate-600">{u.phone ?? u.email}</td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <DepartmentTag department={u.department} />
                    </td>
                    <td className="px-4 py-3">
                      <RoleBadge role={u.role} />
                    </td>
                    <td className="px-4 py-3">
                      <UserStateBadge user={u} />
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-slate-500">{formatRelative(u.lastLoginAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.total > 0 && (
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>
              {meta.from}–{meta.to} of {meta.total}
            </span>
            <div className="flex gap-2">
              <Button variant="secondary" size="sm" icon={<ChevronLeft className="size-4" />} disabled={meta.current_page <= 1} onClick={() => setFilter('page', String(meta.current_page - 1))}>
                Previous
              </Button>
              <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => setFilter('page', String(meta.current_page + 1))}>
                Next
                <ChevronRight className="size-4" />
              </Button>
            </div>
          </div>
        )}
      </Card>

      <CreateUserModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}

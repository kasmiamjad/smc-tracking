import { Ban, Building2, Map, UserCheck, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Avatar, Card, DepartmentTag, PageHeader, Spinner } from '../components/ui'
import { useAuth } from '../lib/auth'
import { formatRelative } from '../lib/format'
import { useDepartments, usePendingUsers, useUserCount } from '../lib/queries'

function StatTile({ label, value, icon, to, accent }: { label: string; value: number | undefined; icon: ReactNode; to: string; accent: string }) {
  return (
    <Link to={to} className="group">
      <Card className="flex items-center gap-4 p-5 transition-shadow group-hover:shadow-md">
        <div className={`rounded-lg p-2.5 ${accent}`}>{icon}</div>
        <div>
          <p className="text-sm text-slate-500">{label}</p>
          <p className="text-2xl font-semibold tabular-nums text-slate-900">{value ?? <Spinner className="size-5" />}</p>
        </div>
      </Card>
    </Link>
  )
}

export function DashboardPage() {
  const { user } = useAuth()
  const pending = usePendingUsers()
  const activeTechs = useUserCount({ role: 'TECHNICIAN', status: 'ACTIVE', blocked: '0' })
  const blocked = useUserCount({ blocked: '1' })
  const departments = useDepartments()

  return (
    <>
      <PageHeader title={`Welcome, ${user?.fullName.split(' ')[0] ?? ''}`} description="Field workforce overview" />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Awaiting approval" value={pending.data?.length} to="/approvals" icon={<UserCheck className="size-6 text-amber-700" />} accent="bg-amber-50" />
        <StatTile label="Active technicians" value={activeTechs.data} to="/users?role=TECHNICIAN&status=ACTIVE" icon={<Users className="size-6 text-emerald-700" />} accent="bg-emerald-50" />
        <StatTile label="Blocked accounts" value={blocked.data} to="/users?blocked=1" icon={<Ban className="size-6 text-red-700" />} accent="bg-red-50" />
        <StatTile label="Departments" value={departments.data?.length} to="/departments" icon={<Building2 className="size-6 text-brand-700" />} accent="bg-brand-50" />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 className="font-semibold text-slate-900">Approval queue</h2>
            <Link to="/approvals" className="text-sm font-medium text-brand-600 hover:text-brand-700">
              Open queue
            </Link>
          </div>
          {pending.isLoading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : pending.data?.length ? (
            <ul className="divide-y divide-slate-100">
              {pending.data.slice(0, 5).map((u) => (
                <li key={u.id}>
                  <Link to={`/users/${u.id}`} className="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                    <Avatar name={u.fullName} color={u.department?.colorHex} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-slate-900">{u.fullName}</p>
                      <p className="text-xs text-slate-500">{u.phone}</p>
                    </div>
                    <DepartmentTag department={u.department} />
                    <span className="hidden text-xs text-slate-400 sm:block">{formatRelative(u.createdAt)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="px-5 py-10 text-center text-sm text-slate-500">No one is waiting for approval.</p>
          )}
        </Card>

        <Card className="flex flex-col items-center justify-center p-8 text-center lg:col-span-2">
          <div className="rounded-full bg-brand-50 p-4 text-brand-600">
            <Map className="size-8" />
          </div>
          <h2 className="mt-4 font-semibold text-slate-900">Live fleet map</h2>
          <p className="mt-1 max-w-xs text-sm text-slate-500">
            Real-time technician positions, team colors and siren alerts will appear here once shifts and GPS tracking are live.
          </p>
        </Card>
      </div>
    </>
  )
}

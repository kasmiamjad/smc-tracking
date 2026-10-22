import clsx from 'clsx'
import { Building2, CalendarDays, Clock, LayoutDashboard, LogOut, Menu, UserCheck, Users, X } from 'lucide-react'
import { useState } from 'react'
import { NavLink, Outlet } from 'react-router'
import { useAuth } from '../lib/auth'
import { usePendingUsers } from '../lib/queries'
import { Avatar, RoleBadge } from './ui'

function NavItems({ onNavigate }: { onNavigate?: () => void }) {
  const pending = usePendingUsers().data?.length ?? 0

  const items = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/approvals', label: 'Approvals', icon: UserCheck, count: pending },
    { to: '/roster', label: 'Roster', icon: CalendarDays },
    { to: '/shifts', label: 'Shifts', icon: Clock },
    { to: '/users', label: 'Users', icon: Users },
    { to: '/departments', label: 'Departments', icon: Building2 },
  ]

  return (
    <nav className="space-y-1">
      {items.map(({ to, label, icon: Icon, end, count }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          onClick={onNavigate}
          className={({ isActive }) =>
            clsx(
              'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
              isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
            )
          }
        >
          <Icon className="size-5" />
          <span className="flex-1">{label}</span>
          {!!count && <span className="rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{count}</span>}
        </NavLink>
      ))}
    </nav>
  )
}

function Brand() {
  return (
    <div className="flex items-center gap-2.5 px-3">
      <img src="/admin/favicon.svg" alt="" className="size-8" />
      <div className="leading-tight">
        <p className="text-sm font-semibold text-slate-900">SMC Field Ops</p>
        <p className="text-xs text-slate-500">Operations console</p>
      </div>
    </div>
  )
}

function UserFooter() {
  const { user, logout } = useAuth()
  if (!user) return null

  return (
    <div className="flex items-center gap-3 border-t border-slate-200 px-3 pt-4">
      <Avatar name={user.fullName} color="#1d4ed8" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-slate-900">{user.fullName}</p>
        <RoleBadge role={user.role} />
      </div>
      <button onClick={logout} className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Sign out" aria-label="Sign out">
        <LogOut className="size-5" />
      </button>
    </div>
  )
}

export function Layout() {
  const [mobileOpen, setMobileOpen] = useState(false)

  return (
    <div className="flex min-h-full">
      {/* Desktop sidebar */}
      <aside className="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-white py-5 lg:flex">
        <Brand />
        <div className="mt-8 flex-1 px-3">
          <NavItems />
        </div>
        <UserFooter />
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/40" onClick={() => setMobileOpen(false)} />
          <aside className="relative flex h-full w-72 flex-col bg-white py-5">
            <div className="flex items-center justify-between pr-3">
              <Brand />
              <button onClick={() => setMobileOpen(false)} className="rounded-md p-1 text-slate-400" aria-label="Close menu">
                <X className="size-5" />
              </button>
            </div>
            <div className="mt-8 flex-1 px-3">
              <NavItems onNavigate={() => setMobileOpen(false)} />
            </div>
            <UserFooter />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
          <button onClick={() => setMobileOpen(true)} className="rounded-md p-1 text-slate-600" aria-label="Open menu">
            <Menu className="size-6" />
          </button>
          <span className="text-sm font-semibold">SMC Field Ops</span>
        </header>
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

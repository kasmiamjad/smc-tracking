import { WifiOff } from 'lucide-react'
import { lazy, Suspense } from 'react'
import { createBrowserRouter, Navigate, Outlet, RouterProvider, useLocation } from 'react-router'
import { Layout } from './components/Layout'
import { ToastProvider } from './components/toast'
import { Button, LoadingBlock } from './components/ui'
import { AuthProvider, useAuth } from './lib/auth'
import { ApprovalsPage } from './pages/ApprovalsPage'
import { DashboardPage } from './pages/DashboardPage'
import { DepartmentsPage } from './pages/DepartmentsPage'
import { LoginPage } from './pages/LoginPage'
import { RosterPage } from './pages/RosterPage'
import { ShiftsPage } from './pages/ShiftsPage'
import { UserDetailPage } from './pages/UserDetailPage'
import { UsersPage } from './pages/UsersPage'

// Leaflet and the export UI load only when someone opens these pages.
const MapPage = lazy(() => import('./pages/MapPage').then((m) => ({ default: m.MapPage })))
const TimesheetsPage = lazy(() => import('./pages/TimesheetsPage').then((m) => ({ default: m.TimesheetsPage })))
const RouteHistoryPage = lazy(() => import('./pages/RouteHistoryPage').then((m) => ({ default: m.RouteHistoryPage })))

function RequireAuth() {
  const { user, loading, restoreFailed, retry } = useAuth()
  const location = useLocation()
  if (loading) return <LoadingBlock />
  if (restoreFailed) {
    return (
      <div className="flex min-h-full items-center justify-center p-6">
        <div className="max-w-sm text-center">
          <WifiOff className="mx-auto size-10 text-slate-400" />
          <h1 className="mt-4 font-semibold text-slate-900">Can't reach the server</h1>
          <p className="mt-1 text-sm text-slate-500">You're still signed in. Check your connection, then try again.</p>
          <Button className="mt-5" onClick={retry}>
            Try again
          </Button>
        </div>
      </div>
    )
  }
  // Remember where they were going so login can send them back there.
  return user ? <Outlet /> : <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
}

function Root() {
  return (
    <AuthProvider>
      <ToastProvider>
        <Outlet />
      </ToastProvider>
    </AuthProvider>
  )
}

const router = createBrowserRouter(
  [
    {
      element: <Root />,
      children: [
        { path: '/login', element: <LoginPage /> },
        {
          element: <RequireAuth />,
          children: [
            {
              element: <Layout />,
              children: [
                { index: true, element: <DashboardPage /> },
                { path: 'approvals', element: <ApprovalsPage /> },
                { path: 'users', element: <UsersPage /> },
                { path: 'users/:id', element: <UserDetailPage /> },
                { path: 'departments', element: <DepartmentsPage /> },
                { path: 'shifts', element: <ShiftsPage /> },
                { path: 'roster', element: <RosterPage /> },
                { path: 'map', element: <Suspense fallback={<LoadingBlock />}><MapPage /></Suspense> },
                { path: 'timesheets', element: <Suspense fallback={<LoadingBlock />}><TimesheetsPage /></Suspense> },
                { path: 'history', element: <Suspense fallback={<LoadingBlock />}><RouteHistoryPage /></Suspense> },
                { path: '*', element: <Navigate to="/" replace /> },
              ],
            },
          ],
        },
      ],
    },
  ],
  { basename: '/admin' },
)

export function App() {
  return <RouterProvider router={router} />
}

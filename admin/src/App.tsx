import { createBrowserRouter, Navigate, Outlet, RouterProvider, useLocation } from 'react-router'
import { Layout } from './components/Layout'
import { ToastProvider } from './components/toast'
import { LoadingBlock } from './components/ui'
import { AuthProvider, useAuth } from './lib/auth'
import { ApprovalsPage } from './pages/ApprovalsPage'
import { DashboardPage } from './pages/DashboardPage'
import { DepartmentsPage } from './pages/DepartmentsPage'
import { LoginPage } from './pages/LoginPage'
import { UserDetailPage } from './pages/UserDetailPage'
import { UsersPage } from './pages/UsersPage'

function RequireAuth() {
  const { user, loading } = useAuth()
  const location = useLocation()
  if (loading) return <LoadingBlock />
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

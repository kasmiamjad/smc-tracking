import { useState, type FormEvent } from 'react'
import { Navigate, useLocation } from 'react-router'
import { Button, ErrorNotice, Field, Input } from '../components/ui'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'

export function LoginPage() {
  const { user, login } = useAuth()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [submitting, setSubmitting] = useState(false)

  if (user) {
    const from = (location.state as { from?: string } | null)?.from
    return <Navigate to={from?.startsWith('/') && from !== '/login' ? from : '/'} replace />
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(email.trim(), password)
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  const fieldError = error instanceof ApiError ? error : null

  return (
    <div className="flex min-h-full items-center justify-center px-4 py-12">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <img src="/admin/favicon.svg" alt="" className="size-12" />
          <h1 className="mt-4 text-xl font-semibold text-slate-900">SMC Field Ops</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to the operations console</p>
        </div>

        <form onSubmit={submit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          {error != null && !fieldError?.field('email') && <ErrorNotice error={error} />}

          <Field label="Email" error={fieldError?.field('email')}>
            {(id) => <Input id={id} type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />}
          </Field>

          <Field label="Password">
            {(id) => (
              <Input id={id} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            )}
          </Field>

          <Button type="submit" className="w-full" loading={submitting}>
            Sign in
          </Button>
        </form>

        <p className="mt-6 text-center text-xs text-slate-400">Technicians sign in from the mobile app.</p>
      </div>
    </div>
  )
}

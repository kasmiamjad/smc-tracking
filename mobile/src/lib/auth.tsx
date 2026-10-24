import * as Device from 'expo-device'
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, OfflineError, onSessionEnded, publicRequest, readTokens, saveTokens, type TokenPair } from './api'
import { stopTracking } from './tracking'
import type { User } from './types'

type State =
  | { status: 'loading' }
  | { status: 'offline' } // signed in, but the server couldn't be reached to confirm it
  | { status: 'signedOut'; reason?: string }
  | { status: 'signedIn'; user: User }

interface AuthValue {
  state: State
  retry: () => void
  login: (phone: string, pin: string) => Promise<void>
  logout: () => Promise<void>
  setUser: (user: User) => void
}

const AuthContext = createContext<AuthValue | null>(null)

/** Why the server ended a session, shown on the sign-in screen. */
export const SESSION_END_MESSAGES: Record<string, string> = {
  ACCOUNT_BLOCKED: 'This phone number has been blocked by administration.',
  ACCOUNT_REJECTED: 'Your registration was rejected.',
  ACCOUNT_PENDING_APPROVAL: 'Your account is waiting for administrator approval.',
  EXPIRED: 'Your session ended. Please sign in again.',
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<State>({ status: 'loading' })

  // Restore the session. No signal at startup keeps the session (and any
  // running tracking) and offers a retry; only a server rejection signs out.
  const restore = useCallback(async () => {
    setState({ status: 'loading' })
    if (!(await readTokens())) return setState({ status: 'signedOut' })
    try {
      const res = await api.get<{ data: User }>('/auth/me')
      setState({ status: 'signedIn', user: res.data })
    } catch (e) {
      if (e instanceof OfflineError || (e instanceof ApiError && e.status >= 500)) {
        setState({ status: 'offline' })
      } else {
        // Usually onSessionEnded has already signed us out with a reason; this covers anything else.
        setState((s) => (s.status === 'loading' ? { status: 'signedOut', reason: e instanceof Error ? e.message : undefined } : s))
      }
    }
  }, [])

  useEffect(() => {
    restore()
  }, [restore])

  // The server revoked the session (blocked, PIN reset, rejected, expired).
  useEffect(
    () =>
      onSessionEnded((reason) => {
        stopTracking().catch(() => {})
        setState({ status: 'signedOut', reason: SESSION_END_MESSAGES[reason] ?? SESSION_END_MESSAGES.EXPIRED })
      }),
    [],
  )

  const login = useCallback(async (phone: string, pin: string) => {
    const deviceName = [Device.manufacturer, Device.modelName].filter(Boolean).join(' ') || 'Android'
    const res = await publicRequest<TokenPair & { user: User }>('POST', '/auth/login-pin', { phone, pin, deviceName })
    await saveTokens({ token: res.token, refreshToken: res.refreshToken })
    setState({ status: 'signedIn', user: res.user })
  }, [])

  const logout = useCallback(async () => {
    await stopTracking().catch(() => {})
    try {
      await api.post('/auth/logout')
    } catch {
      // offline: the tokens are dropped locally anyway and expire server-side
    }
    await saveTokens(null)
    setState({ status: 'signedOut' })
  }, [])

  const setUser = useCallback((user: User) => setState({ status: 'signedIn', user }), [])

  return <AuthContext.Provider value={{ state, retry: restore, login, logout, setUser }}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}

import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasTokens, publicRequest, saveTokens, SESSION_EXPIRED_EVENT } from './api'
import type { TokenPair, User } from './types'

interface AuthValue {
  user: User | null
  loading: boolean
  /** Tokens exist but the server couldn't be reached to confirm them. */
  restoreFailed: boolean
  retry: () => void
  isAdmin: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(hasTokens())
  const queryClient = useQueryClient()

  const clear = useCallback(() => {
    saveTokens(null)
    setUser(null)
    queryClient.clear()
  }, [queryClient])

  // Restore the session on load. Only a rejected token signs out; a network
  // blip, a slow server or a deploy in progress just retries.
  const [restoreFailed, setRestoreFailed] = useState(false)
  const [run, setRun] = useState(0) // bump to re-check the session
  const retriesLeft = useRef(3)

  useEffect(() => {
    if (!hasTokens()) return
    let cancelled = false
    api
      .get<{ data: User }>('/auth/me')
      .then((res) => {
        if (cancelled) return
        setUser(res.data)
        setLoading(false)
      })
      .catch((error: unknown) => {
        if (cancelled) return
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
          clear()
          setLoading(false)
        } else if (retriesLeft.current-- > 0) {
          setTimeout(() => setRun((r) => r + 1), 1500)
        } else {
          setRestoreFailed(true)
          setLoading(false)
        }
      })
    return () => {
      cancelled = true
    }
  }, [clear, run])

  const retry = useCallback(() => {
    retriesLeft.current = 3
    setRestoreFailed(false)
    setLoading(true)
    setRun((r) => r + 1)
  }, [])

  useEffect(() => {
    window.addEventListener(SESSION_EXPIRED_EVENT, clear)
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, clear)
  }, [clear])

  const login = useCallback(async (email: string, password: string) => {
    const res = await publicRequest<TokenPair & { user: User }>('POST', '/auth/login', { email, password })
    saveTokens({ token: res.token, tokenExpiresAt: res.tokenExpiresAt, refreshToken: res.refreshToken, refreshTokenExpiresAt: res.refreshTokenExpiresAt })
    setUser(res.user)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      clear()
    }
  }, [clear])

  return (
    <AuthContext.Provider value={{ user, loading, restoreFailed, retry, isAdmin: user?.role === 'ADMIN', login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, hasTokens, publicRequest, saveTokens, SESSION_EXPIRED_EVENT } from './api'
import type { TokenPair, User } from './types'

interface AuthValue {
  user: User | null
  loading: boolean
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

  // Restore the session on load.
  useEffect(() => {
    if (!hasTokens()) return
    api
      .get<{ data: User }>('/auth/me')
      .then((res) => setUser(res.data))
      .catch(clear)
      .finally(() => setLoading(false))
  }, [clear])

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
    <AuthContext.Provider value={{ user, loading, isAdmin: user?.role === 'ADMIN', login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}

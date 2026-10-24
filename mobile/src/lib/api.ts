import * as SecureStore from 'expo-secure-store'
import { API_URL } from '../config'

const TOKENS_KEY = 'smc.tokens'

export interface TokenPair {
  token: string
  refreshToken: string
}

export class ApiError extends Error {
  status: number
  code: string
  fieldErrors: Record<string, string[]>
  extra: Record<string, unknown>

  constructor(status: number, code: string, message: string, fieldErrors: Record<string, string[]> = {}, extra: Record<string, unknown> = {}) {
    super(message)
    this.status = status
    this.code = code
    this.fieldErrors = fieldErrors
    this.extra = extra
  }

  field(name: string): string | undefined {
    return this.fieldErrors[name]?.[0]
  }
}

/** Network unreachable (no signal, server down). Never a reason to sign out. */
export class OfflineError extends Error {}

// ---- tokens (Android Keystore-backed) --------------------------------------

export async function readTokens(): Promise<TokenPair | null> {
  const raw = await SecureStore.getItemAsync(TOKENS_KEY)
  return raw ? (JSON.parse(raw) as TokenPair) : null
}

export async function saveTokens(tokens: TokenPair | null): Promise<void> {
  if (tokens) await SecureStore.setItemAsync(TOKENS_KEY, JSON.stringify({ token: tokens.token, refreshToken: tokens.refreshToken }))
  else await SecureStore.deleteItemAsync(TOKENS_KEY)
}

const sessionEndedListeners = new Set<(reason: string) => void>()

/** The UI subscribes to be told when the server ends the session (blocked, PIN reset, expired). */
export function onSessionEnded(listener: (reason: string) => void): () => void {
  sessionEndedListeners.add(listener)
  return () => sessionEndedListeners.delete(listener)
}

async function endSession(reason: string): Promise<void> {
  await saveTokens(null)
  sessionEndedListeners.forEach((l) => l(reason))
}

// ---- requests --------------------------------------------------------------

type Body = Record<string, unknown> | undefined

async function send(method: string, path: string, token: string | undefined, body?: Body): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`
  if (body) headers['Content-Type'] = 'application/json'

  try {
    return await fetch(API_URL + path, { method, headers, body: body ? JSON.stringify(body) : undefined })
  } catch {
    throw new OfflineError('No connection to the server.')
  }
}

async function toApiError(res: Response): Promise<ApiError> {
  let p: { error?: string; message?: string; errors?: Record<string, string[]>; [k: string]: unknown } = {}
  try {
    p = await res.json()
  } catch {
    // not JSON
  }
  const { error, message, errors, ...extra } = p
  return new ApiError(res.status, error ?? (res.status === 422 ? 'VALIDATION_FAILED' : `HTTP_${res.status}`), message || 'Something went wrong.', errors ?? {}, extra)
}

let refreshing: Promise<'refreshed' | 'rejected' | 'unavailable'> | null = null

function refresh(): Promise<'refreshed' | 'rejected' | 'unavailable'> {
  refreshing ??= (async () => {
    const tokens = await readTokens()
    if (!tokens) return 'rejected' as const
    let res: Response
    try {
      res = await send('POST', '/auth/refresh', tokens.refreshToken)
    } catch {
      return 'unavailable' as const
    }
    if (res.status === 401 || res.status === 403) return 'rejected' as const
    if (!res.ok) return 'unavailable' as const
    await saveTokens((await res.json()) as TokenPair)
    return 'refreshed' as const
  })().finally(() => {
    refreshing = null
  })
  return refreshing
}

/**
 * Authenticated request. Refreshes an expired access token once; signs out
 * only when the server rejects the session, never because of an outage.
 */
export async function request<T>(method: string, path: string, body?: Body): Promise<T> {
  let res = await send(method, path, (await readTokens())?.token, body)

  if (res.status === 401) {
    const result = await refresh()
    if (result === 'unavailable') throw new OfflineError('No connection to the server.')
    if (result === 'refreshed') res = await send(method, path, (await readTokens())?.token, body)
    if (result === 'rejected' || res.status === 401) {
      await endSession('EXPIRED')
      throw new ApiError(401, 'UNAUTHENTICATED', 'Please sign in again.')
    }
  }

  if (!res.ok) {
    const error = await toApiError(res)
    // Blocked / rejected / locked while signed in: the server already revoked the tokens.
    if (res.status === 403 && ['ACCOUNT_BLOCKED', 'ACCOUNT_REJECTED', 'ACCOUNT_PENDING_APPROVAL'].includes(error.code)) {
      await endSession(error.code)
    }
    throw error
  }

  return res.status === 204 ? (undefined as T) : ((await res.json()) as T)
}

/** Unauthenticated call (login, register, departments). */
export async function publicRequest<T>(method: string, path: string, body?: Body): Promise<T> {
  const res = await send(method, path, undefined, body)
  if (!res.ok) throw await toApiError(res)
  return (await res.json()) as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: Body) => request<T>('POST', path, body),
}

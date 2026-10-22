import type { TokenPair } from './types'

const BASE = '/api/v1'
const STORAGE_KEY = 'smc.console.tokens'

export class ApiError extends Error {
  status: number
  code: string
  fieldErrors: Record<string, string[]>

  constructor(status: number, code: string, message: string, fieldErrors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.code = code
    this.fieldErrors = fieldErrors
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.fieldErrors[name]?.[0]
  }
}

// ---- token storage --------------------------------------------------------

function readTokens(): TokenPair | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as TokenPair) : null
  } catch {
    return null
  }
}

export function saveTokens(tokens: TokenPair | null): void {
  try {
    if (tokens) localStorage.setItem(STORAGE_KEY, JSON.stringify(tokens))
    else localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Private mode etc.: the session just won't survive a reload.
  }
}

export function hasTokens(): boolean {
  return readTokens() !== null
}

/** Fired when the session can no longer be refreshed; the auth provider signs out. */
export const SESSION_EXPIRED_EVENT = 'smc:session-expired'

// ---- requests -------------------------------------------------------------

type Body = Record<string, unknown> | FormData | undefined

async function toApiError(res: Response): Promise<ApiError> {
  let payload: { error?: string; message?: string; errors?: Record<string, string[]> } = {}
  try {
    payload = await res.json()
  } catch {
    // non-JSON error page
  }
  const code = payload.error ?? (res.status === 422 ? 'VALIDATION_FAILED' : `HTTP_${res.status}`)
  return new ApiError(res.status, code, payload.message || res.statusText || 'Request failed', payload.errors ?? {})
}

function send(method: string, path: string, token: string | undefined, body?: Body): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`
  if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json'

  return fetch(BASE + path, {
    method,
    headers,
    body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined,
  })
}

type RefreshResult = 'refreshed' | 'rejected' | 'unavailable'

let refreshing: Promise<RefreshResult> | null = null

/** Exchange the refresh token once, even if many requests hit 401 together. */
function refreshTokens(): Promise<RefreshResult> {
  refreshing ??= (async (): Promise<RefreshResult> => {
    const tokens = readTokens()
    if (!tokens) return 'rejected'
    let res: Response
    try {
      res = await send('POST', '/auth/refresh', tokens.refreshToken)
    } catch {
      return 'unavailable' // network down: keep the session, try again later
    }
    if (res.status === 401 || res.status === 403) return 'rejected'
    if (!res.ok) return 'unavailable' // 5xx / deploy in progress
    const data = (await res.json()) as TokenPair
    saveTokens({ token: data.token, tokenExpiresAt: data.tokenExpiresAt, refreshToken: data.refreshToken, refreshTokenExpiresAt: data.refreshTokenExpiresAt })
    return 'refreshed'
  })().finally(() => {
    refreshing = null
  })
  return refreshing
}

/** Signs out only when the server says the session is over, never on an outage. */
async function authorizedFetch(method: string, path: string, body?: Body): Promise<Response> {
  let res = await send(method, path, readTokens()?.token, body)

  if (res.status === 401 && readTokens()) {
    const result = await refreshTokens()
    if (result === 'refreshed') {
      res = await send(method, path, readTokens()?.token, body)
    }
    if (result === 'rejected' || (result === 'refreshed' && res.status === 401)) {
      saveTokens(null)
      window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT))
    }
  }

  return res
}

export async function request<T>(method: string, path: string, body?: Body): Promise<T> {
  const res = await authorizedFetch(method, path, body)
  if (!res.ok) throw await toApiError(res)
  if (res.status === 204) return undefined as T
  return (await res.json()) as T
}

/** Unauthenticated call (login). */
export async function publicRequest<T>(method: string, path: string, body?: Body): Promise<T> {
  const res = await send(method, path, undefined, body)
  if (!res.ok) throw await toApiError(res)
  return (await res.json()) as T
}

/** Downloads need the bearer token, so fetch the file and hand back a blob URL. */
export async function fetchBlobUrl(absoluteUrl: string): Promise<string> {
  const path = new URL(absoluteUrl, window.location.origin).pathname.replace(/^\/api\/v1/, '')
  const res = await authorizedFetch('GET', path)
  if (!res.ok) throw await toApiError(res)
  return URL.createObjectURL(await res.blob())
}

/** Download an authenticated file (exports) and hand it to the browser as a save. */
export async function downloadFile(path: string): Promise<void> {
  const res = await authorizedFetch('GET', path)
  if (!res.ok) throw await toApiError(res)
  const name = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? 'download'
  const url = URL.createObjectURL(await res.blob())
  const a = Object.assign(document.createElement('a'), { href: url, download: name })
  document.body.append(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 30_000)
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: Body) => request<T>('POST', path, body),
  put: <T>(path: string, body?: Body) => request<T>('PUT', path, body),
  patch: <T>(path: string, body?: Body) => request<T>('PATCH', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
}

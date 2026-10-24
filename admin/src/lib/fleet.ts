import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from './api'
import type { FleetMember, Freshness } from './types'

interface Thresholds {
  live: number
  lagging: number
}

interface FreshnessRules {
  moving: Thresholds
  stationary: Thresholds
  movingSpeed: number
}

// Used until the first snapshot arrives; the server's config is the source of truth.
const DEFAULT_RULES: FreshnessRules = {
  moving: { live: 10, lagging: 30 }, // spec 4.4, phone pings every 4s
  stationary: { live: 25, lagging: 60 }, // phone backs off to every 15s
  movingSpeed: 1,
}

interface RealtimeConfig {
  enabled: boolean
  key?: string
  host?: string
  port?: number
  scheme?: 'http' | 'https'
  authEndpoint?: string
  channel?: string
  event?: string
}

interface FleetResponse {
  data: FleetMember[]
  meta: {
    generatedAt: string
    timezone: string
    freshness: { moving: Thresholds; stationary: Thresholds }
    movingSpeed: number
    onShift: number
    live: number
    lagging: number
    offline: number
  }
  realtime: RealtimeConfig
}

interface PositionEvent {
  userId: string
  assignmentId: string
  latitude: number
  longitude: number
  accuracy: number | null
  speed: number | null
  heading: number | null
  batteryLevel: number | null
  isCharging: boolean | null
  recordedAt: string
}

export type RealtimeStatus = 'connecting' | 'live' | 'polling'

/**
 * Judge a pin against the rhythm its phone should be on: every 4s while
 * moving, every 15s once stationary. Unknown speed counts as moving (stricter).
 * Mirrors App\Support\Freshness on the server.
 */
export function freshnessOf(ageSeconds: number | null, speed: number | null | undefined, rules: FreshnessRules = DEFAULT_RULES): Freshness {
  if (ageSeconds === null) return 'OFFLINE'
  const t = speed != null && speed <= rules.movingSpeed ? rules.stationary : rules.moving
  if (ageSeconds <= t.live) return 'LIVE'
  if (ageSeconds <= t.lagging) return 'LAGGING'
  return 'OFFLINE'
}

/** Open a private-fleet subscription and patch pushed positions into the cached snapshot. */
function useFleetSocket(config: RealtimeConfig | undefined): RealtimeStatus {
  const qc = useQueryClient()
  // Connection state of the current socket; meaningless while realtime is disabled.
  const [socket, setSocket] = useState<'connecting' | 'live' | 'down'>('connecting')

  useEffect(() => {
    if (!config?.enabled || !config.key) return

    let echo: { disconnect: () => void } | null = null
    let cancelled = false

    // Loaded on demand: servers without WebSockets never download the client.
    Promise.all([import('laravel-echo'), import('pusher-js')]).then(([{ default: Echo }, { default: Pusher }]) => {
      if (cancelled) return
      ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
      echo = connect(Echo)
    })

    const connect = (Echo: typeof import('laravel-echo').default) => {
      const echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // Authorise through the API client so expired tokens are refreshed.
        authorizer: (channel: { name: string }) => ({
          authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
            api
              .post<{ auth: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
              .then((data) => callback(null, data))
              .catch((error: Error) => callback(error, null))
          },
        }),
      })
  
      const connection = echo.connector.pusher.connection
      connection.bind('connecting', () => setSocket('connecting'))
      connection.bind('connected', () => setSocket('live'))
      connection.bind('unavailable', () => setSocket('down'))
      connection.bind('failed', () => setSocket('down'))
      connection.bind('disconnected', () => setSocket('down'))
  
      echo.private((config.channel ?? 'private-fleet').replace(/^private-/, '')).listen(config.event ?? '.position.updated', (e: PositionEvent) => {
        qc.setQueryData<FleetResponse>(['fleet'], (prev) => {
          if (!prev) return prev
          const known = prev.data.some((m) => m.userId === e.userId && m.assignmentId === e.assignmentId)
          if (!known) {
            // Someone new checked in: fetch the full snapshot to get their name and team.
            qc.invalidateQueries({ queryKey: ['fleet'] })
            return prev
          }
          return {
            ...prev,
            data: prev.data.map((m) =>
              m.userId === e.userId
                ? {
                    ...m,
                    position: {
                      latitude: e.latitude,
                      longitude: e.longitude,
                      accuracy: e.accuracy,
                      speed: e.speed,
                      heading: e.heading,
                      batteryLevel: e.batteryLevel,
                      isCharging: e.isCharging,
                      recordedAt: e.recordedAt,
                    },
                  }
                : m,
            ),
          }
        })
      })
  
      return echo
    }

    return () => {
      cancelled = true
      echo?.disconnect()
    }
  }, [config?.enabled, config?.key, config?.host, config?.port, config?.scheme, config?.channel, config?.event, qc])

  if (!config?.enabled) return 'polling'
  return socket === 'live' ? 'live' : socket === 'down' ? 'polling' : 'connecting'
}

/**
 * Everyone on shift, with freshness recomputed every second. Polls every
 * 5s; once the WebSocket is live, polling slows to a 30s safety net.
 */
export function useLiveFleet() {
  const socketLive = useRef(false)

  const query = useQuery({
    queryKey: ['fleet'],
    queryFn: async () => {
      const res = await api.get<FleetResponse>('/admin/fleet/live')
      // Local clock minus server clock, so ages are right even if this PC's clock is off.
      return { ...res, skewMs: Date.now() - new Date(res.meta.generatedAt).getTime() }
    },
    refetchInterval: () => (socketLive.current ? 30_000 : 5_000),
    staleTime: 0,
  })

  const status = useFleetSocket(query.data?.realtime)
  useEffect(() => {
    socketLive.current = status === 'live'
  }, [status])

  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])

  const members = useMemo(() => {
    const meta = query.data?.meta
    const rules: FreshnessRules = meta?.freshness ? { ...meta.freshness, movingSpeed: meta.movingSpeed } : DEFAULT_RULES
    return (query.data?.data ?? []).map((m) => {
      const skew = query.data?.skewMs ?? 0
      const age = m.position ? Math.max(0, Math.round((now - skew - new Date(m.position.recordedAt).getTime()) / 1000)) : null
      return { ...m, ageSeconds: age, freshness: freshnessOf(age, m.position?.speed, rules) }
    })
  }, [query.data, now])

  const counts = useMemo(
    () => ({
      onShift: members.length,
      live: members.filter((m) => m.freshness === 'LIVE').length,
      lagging: members.filter((m) => m.freshness === 'LAGGING').length,
      offline: members.filter((m) => m.freshness === 'OFFLINE').length,
    }),
    [members],
  )

  return { members, counts, status, timezone: query.data?.meta.timezone, isLoading: query.isLoading, error: query.error }
}

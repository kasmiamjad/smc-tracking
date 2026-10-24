import * as Location from 'expo-location'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, AppState, Linking, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { api, ApiError, OfflineError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { currentPosition, ensureLocationPermission, flushQueue, startTracking, stopTracking, trackingStatus, type PermissionProblem } from '../lib/tracking'
import type { Assignment, MyShifts, User } from '../lib/types'
import { Button, Card, colors, Notice } from '../ui'

const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const day = (iso: string) => new Date(iso).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })

function elapsed(since: string, now: number): string {
  const s = Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000))
  return `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m`
}

const PERMISSION_HELP: Record<PermissionProblem, string> = {
  SERVICES_OFF: 'Location is turned off on this phone. Turn it on in quick settings, then try again.',
  FOREGROUND_DENIED: 'Location permission is needed to check in. Allow it in Settings → Apps → SMC Field Ops → Permissions.',
  BACKGROUND_DENIED: 'Choose "Allow all the time" for location, so your position keeps updating while the phone is in your pocket. Settings → Apps → SMC Field Ops → Permissions → Location.',
}

function errorText(e: unknown): string {
  if (e instanceof OfflineError) return 'No connection. Try again when you have signal.'
  if (e instanceof ApiError) return e.message
  return 'Something went wrong. Please try again.'
}

export function HomeScreen({ user }: { user: User }) {
  const { logout } = useAuth()
  const insets = useSafeAreaInsets()
  const [shifts, setShifts] = useState<MyShifts | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<{ tone: 'red' | 'amber' | 'green'; text: string; settings?: boolean } | null>(null)
  const [status, setStatus] = useState<{ running: boolean; queued: number; lastUploadAt: string | null; mode: string } | null>(null)
  const [now, setNow] = useState(() => Date.now())
  const mounted = useRef(true)

  const load = useCallback(async () => {
    try {
      const res = await api.get<MyShifts>('/shifts/my-shifts')
      if (!mounted.current) return
      setShifts(res)

      // Keep the phone in step with the server: track exactly while checked in.
      const running = (await trackingStatus()).running
      if (res.active && !running) {
        const bg = await Location.getBackgroundPermissionsAsync()
        if (bg.status === 'granted') await startTracking(res.active.id)
        else setMessage({ tone: 'red', text: PERMISSION_HELP.BACKGROUND_DENIED, settings: true })
      } else if (!res.active && running) {
        await stopTracking() // checked out elsewhere, or auto-checked-out by the server
      }
    } catch (e) {
      if (mounted.current && !(e instanceof ApiError && e.status === 401)) setMessage({ tone: 'amber', text: errorText(e) })
    } finally {
      if (mounted.current) {
        setLoading(false)
        setStatus(await trackingStatus())
      }
    }
  }, [])

  useEffect(() => {
    mounted.current = true
    load()
    const poll = setInterval(load, 30_000)
    const tick = setInterval(async () => {
      setNow(Date.now())
      setStatus(await trackingStatus())
    }, 5_000)
    const sub = AppState.addEventListener('change', (s) => s === 'active' && load())
    return () => {
      mounted.current = false
      clearInterval(poll)
      clearInterval(tick)
      sub.remove()
    }
  }, [load])

  async function checkIn(a: Assignment) {
    setMessage(null)
    setBusy(a.id)
    try {
      const problem = await ensureLocationPermission()
      if (problem) return setMessage({ tone: 'red', text: PERMISSION_HELP[problem], settings: problem !== 'SERVICES_OFF' })

      const location = await currentPosition()
      const res = await api.post<{ data: Assignment }>('/shifts/check-in', { assignmentId: a.id, location })
      await startTracking(a.id)
      setMessage(res.data.checkInOutsideZone ? { tone: 'amber', text: "Checked in. You're outside this shift's work zone, so dispatch has been shown that." } : { tone: 'green', text: 'Checked in. Location sharing is on.' })
      await load()
    } catch (e) {
      setMessage({ tone: 'red', text: e instanceof ApiError && e.code === 'TOO_EARLY' ? `Too early: ${e.message}` : errorText(e) })
    } finally {
      setBusy(null)
    }
  }

  async function checkOut(a: Assignment) {
    setMessage(null)
    setBusy(a.id)
    try {
      await flushQueue()
      const last = await Location.getLastKnownPositionAsync().catch(() => null)
      const location = last ? { lat: last.coords.latitude, lng: last.coords.longitude } : undefined
      // Server first: if there's no signal, the shift stays open and tracking must keep running.
      await api.post('/shifts/check-out', { assignmentId: a.id, location })
      await stopTracking()
      await flushQueue() // anything recorded during the shift that's still queued
      setMessage({ tone: 'green', text: 'Checked out. Location sharing is off.' })
      await load()
    } catch (e) {
      setMessage({ tone: 'red', text: errorText(e) })
    } finally {
      setBusy(null)
    }
  }

  const confirm = (title: string, body: string, action: string, run: () => void) =>
    Alert.alert(title, body, [
      { text: 'Cancel', style: 'cancel' },
      { text: action, onPress: run },
    ])

  const active = shifts?.active ?? null
  const opensMs = (shifts?.checkInOpensMinutesBefore ?? 60) * 60_000

  return (
    <ScrollView
      style={{ flex: 1, backgroundColor: colors.bg }}
      contentContainerStyle={{ padding: 20, paddingTop: insets.top + 16, paddingBottom: insets.bottom + 24 }}
      refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
    >
      <View style={styles.header}>
        <View style={{ flex: 1 }}>
          <Text style={styles.hello}>Hello, {user.fullName.split(' ')[0]}</Text>
          <Text style={styles.team}>{user.department?.name ?? 'No team assigned'}</Text>
        </View>
        <Button
          title="Sign out"
          variant="secondary"
          style={{ minHeight: 40 }}
          onPress={() => (active ? Alert.alert('Check out first', 'You are on shift. Check out before signing out.') : confirm('Sign out?', '', 'Sign out', logout))}
        />
      </View>

      {/* Spec 6.2: always make it obvious whether tracking is on. */}
      <View style={[styles.banner, { backgroundColor: status?.running ? colors.greenSoft : '#F1F5F9' }]}>
        <View style={[styles.dot, { backgroundColor: status?.running ? colors.green : '#94A3B8' }]} />
        <Text style={[styles.bannerText, { color: status?.running ? colors.green : colors.muted }]}>
          {status?.running ? 'Location sharing is ON' : 'Location sharing is off'}
        </Text>
      </View>

      {message && (
        <View>
          <Notice tone={message.tone}>{message.text}</Notice>
          {message.settings && <Button title="Open settings" variant="secondary" onPress={() => Linking.openSettings()} style={{ marginTop: -8, marginBottom: 16 }} />}
        </View>
      )}

      {active ? (
        <Card style={{ borderColor: colors.green, borderWidth: 2, marginBottom: 20 }}>
          <Text style={styles.cardLabel}>ON SHIFT</Text>
          <Text style={styles.shiftName}>{active.shift?.name}</Text>
          <Text style={styles.big}>{elapsed(active.checkedInAt!, now)}</Text>
          <Text style={styles.meta}>
            Checked in {time(active.checkedInAt!)} · ends {time(active.scheduledEndAt)}
          </Text>
          {status && (
            <Text style={styles.meta}>
              {status.queued > 0 ? `${status.queued} location updates waiting for signal` : 'All location updates sent'}
              {status.lastUploadAt ? ` · last sent ${time(status.lastUploadAt)}` : ''}
            </Text>
          )}
          <Button
            title="Check out"
            variant="danger"
            style={{ marginTop: 16 }}
            loading={busy === active.id}
            onPress={() => confirm('Check out?', 'This ends your shift and turns off location sharing.', 'Check out', () => checkOut(active))}
          />
        </Card>
      ) : null}

      <Text style={styles.section}>{active ? 'Coming up' : 'Your shifts'}</Text>
      {!loading && !shifts?.upcoming.length && <Text style={styles.empty}>No shifts in the next 7 days.</Text>}

      {shifts?.upcoming.map((a) => {
        const start = new Date(a.scheduledStartAt).getTime()
        const canCheckIn = !active && now >= start - opensMs && now < new Date(a.scheduledEndAt).getTime()
        return (
          <Card key={a.id} style={{ marginBottom: 12 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text style={styles.shiftName}>{a.shift?.name}</Text>
              <Text style={styles.meta}>{day(a.scheduledStartAt)}</Text>
            </View>
            <Text style={styles.time}>
              {time(a.scheduledStartAt)} – {time(a.scheduledEndAt)}
            </Text>
            {canCheckIn ? (
              <Button
                title="Check in"
                variant="success"
                style={{ marginTop: 12 }}
                loading={busy === a.id}
                onPress={() => confirm(`Start ${a.shift?.name}?`, 'Your location will be shared with dispatch until you check out.', 'Check in', () => checkIn(a))}
              />
            ) : !active && start - now > opensMs ? (
              <Text style={styles.meta}>Check-in opens at {time(new Date(start - opensMs).toISOString())}</Text>
            ) : null}
          </Card>
        )
      })}
    </ScrollView>
  )
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', alignItems: 'center', marginBottom: 16 },
  hello: { fontSize: 24, fontWeight: '700', color: colors.text },
  team: { fontSize: 15, color: colors.muted, marginTop: 2 },
  banner: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 14, borderRadius: 12, marginBottom: 16 },
  bannerText: { fontSize: 15, fontWeight: '600' },
  dot: { width: 12, height: 12, borderRadius: 6 },
  cardLabel: { fontSize: 12, fontWeight: '700', color: colors.green, letterSpacing: 1 },
  shiftName: { fontSize: 18, fontWeight: '700', color: colors.text },
  big: { fontSize: 40, fontWeight: '700', color: colors.text, marginVertical: 4, fontVariant: ['tabular-nums'] },
  time: { fontSize: 22, fontWeight: '600', color: colors.text, marginTop: 4, fontVariant: ['tabular-nums'] },
  meta: { fontSize: 14, color: colors.muted, marginTop: 4 },
  section: { fontSize: 13, fontWeight: '700', color: colors.muted, letterSpacing: 1, marginBottom: 10, textTransform: 'uppercase' },
  empty: { fontSize: 15, color: colors.muted },
})

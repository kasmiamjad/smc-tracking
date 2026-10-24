import * as Battery from 'expo-battery'
import * as Location from 'expo-location'
import * as TaskManager from 'expo-task-manager'
import { TRACKING } from '../config'
import { api, ApiError, OfflineError } from './api'
import { enqueuePings, getKv, peekPings, queuedCount, removePings, setKv, type Ping } from './db'

/**
 * Background telemetry (spec 4.2 / MOD-10).
 *
 * While checked in, Android runs a foreground service (persistent
 * notification) that delivers fixes every 4s while moving, backing off to
 * 15s after 2 minutes stationary. Every fix is queued in SQLite first and
 * then uploaded in batches, so nothing is lost without signal. Tracking
 * stops at check-out; nothing is recorded off shift (spec 6.2).
 */
export const LOCATION_TASK = 'smc-location-tracking'

type Mode = 'moving' | 'stationary'

function taskOptions(mode: Mode): Location.LocationTaskOptions {
  return {
    accuracy: Location.Accuracy.High,
    timeInterval: mode === 'moving' ? TRACKING.movingIntervalMs : TRACKING.stationaryIntervalMs,
    distanceInterval: 0,
    pausesUpdatesAutomatically: false,
    foregroundService: {
      notificationTitle: 'Shift active: location sharing on',
      notificationBody: 'Dispatch can see your location until you check out.',
      notificationColor: '#1D4ED8',
      killServiceOnDestroy: false,
    },
  }
}

async function readBattery(): Promise<{ level: number | null; charging: boolean | null }> {
  try {
    const [level, state] = await Promise.all([Battery.getBatteryLevelAsync(), Battery.getBatteryStateAsync()])
    return {
      level: level >= 0 ? Math.round(level * 100) : null,
      charging: state === Battery.BatteryState.CHARGING || state === Battery.BatteryState.FULL,
    }
  } catch {
    return { level: null, charging: null }
  }
}

function toPing(loc: Location.LocationObject, battery: { level: number | null; charging: boolean | null }): Ping {
  const { latitude, longitude, accuracy, speed, heading } = loc.coords
  return {
    latitude,
    longitude,
    accuracy: accuracy ?? null,
    speed: speed != null && speed >= 0 ? speed : null,
    heading: heading != null && heading >= 0 ? heading : null,
    batteryLevel: battery.level,
    isCharging: battery.charging,
    recordedAt: new Date(loc.timestamp).toISOString(),
  }
}

function metresBetween(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const rad = Math.PI / 180
  const h = Math.sin(((b.latitude - a.latitude) * rad) / 2) ** 2 + Math.cos(a.latitude * rad) * Math.cos(b.latitude * rad) * Math.sin(((b.longitude - a.longitude) * rad) / 2) ** 2
  return 2 * 6_371_000 * Math.asin(Math.sqrt(h))
}

/**
 * Speed in m/s, or null when unknown.
 *
 * The phone's own reading (GPS Doppler) is trusted whenever it gives one,
 * including 0: indoors a phone can flip 50-150 m between a GPS fix and a
 * Wi-Fi estimate while standing still, and working speed out from those
 * jumps produced fake "58 km/h" readings. Only when the phone reports no
 * speed at all is it derived from distance / time since the previous fix.
 */
async function measureSpeeds(locations: Location.LocationObject[]): Promise<(number | null)[]> {
  const prevRaw = await getKv('lastFix')
  let prev = prevRaw ? (JSON.parse(prevRaw) as { latitude: number; longitude: number; t: number }) : null
  const speeds = locations.map((l) => {
    const reported = l.coords.speed != null && l.coords.speed >= 0 ? l.coords.speed : null
    let derived: number | null = null
    if (reported === null && prev) {
      const seconds = (l.timestamp - prev.t) / 1000
      const metres = metresBetween(prev, l.coords)
      if (seconds > 0 && metres > Math.max(10, l.coords.accuracy ?? 0)) derived = metres / seconds
    }
    prev = { latitude: l.coords.latitude, longitude: l.coords.longitude, t: l.timestamp }
    return reported ?? derived
  })
  if (prev) await setKv('lastFix', JSON.stringify(prev))
  return speeds
}

/** Switch between the 4s and 15s cadence based on movement. */
async function adaptInterval(speeds: (number | null)[]): Promise<void> {
  const now = Date.now()
  const moving = speeds.some((s) => s !== null && s > TRACKING.movingSpeed)
  if (moving) await setKv('lastMovingAt', String(now))

  const mode = ((await getKv('mode')) as Mode | null) ?? 'moving'
  const lastMovingAt = Number((await getKv('lastMovingAt')) ?? now)

  const next: Mode = moving ? 'moving' : now - lastMovingAt > TRACKING.stationaryAfterMs ? 'stationary' : mode
  if (next !== mode) {
    await setKv('mode', next)
    // Restarting with the same task name replaces the options; the service keeps running.
    await Location.startLocationUpdatesAsync(LOCATION_TASK, taskOptions(next))
  }
}

let flushing: Promise<void> | null = null

/** Upload queued pings in batches. Safe to call often; concurrent calls share one run. */
export function flushQueue(): Promise<void> {
  flushing ??= (async () => {
    try {
      for (let round = 0; round < 20; round++) {
        const { ids, pings } = await peekPings(TRACKING.maxBatch)
        if (!ids.length) return
        try {
          await api.post('/telemetry/ping', { pings })
        } catch (e) {
          if (e instanceof OfflineError) return // keep them for later
          if (e instanceof ApiError && (e.status === 429 || e.status >= 500)) return // back off, retry next fix
          if (e instanceof ApiError && e.status === 401) return // session ended; UI handles sign-out
          // Anything else (e.g. 422) would never succeed; drop the batch rather than block the queue.
        }
        await removePings(ids)
        await setKv('lastUploadAt', new Date().toISOString())
      }
    } finally {
      flushing = null
    }
  })()
  return flushing
}

// Must be defined at module load (imported from index.ts) so Android can
// deliver fixes even when the app's UI isn't running.
TaskManager.defineTask<{ locations: Location.LocationObject[] }>(LOCATION_TASK, async ({ data, error }) => {
  if (error || !data?.locations?.length) return
  if (!(await getKv('assignmentId'))) {
    // Checked out (or signed out) but the OS delivered a late fix: never record it.
    await stopTracking()
    return
  }
  const battery = await readBattery()
  const speeds = await measureSpeeds(data.locations)
  // Send the best speed we have, so the server's moving/stationary timesheet figures work on every phone.
  await enqueuePings(data.locations.map((l, i) => ({ ...toPing(l, battery), speed: speeds[i] ?? null })))
  await adaptInterval(speeds)
  await flushQueue()
})

// ---- control ---------------------------------------------------------------

export type PermissionProblem = 'FOREGROUND_DENIED' | 'BACKGROUND_DENIED' | 'SERVICES_OFF'

/** Ask for "while using" then "all the time" location. Returns a problem, or null when ready. */
export async function ensureLocationPermission(): Promise<PermissionProblem | null> {
  if (!(await Location.hasServicesEnabledAsync())) return 'SERVICES_OFF'
  const fg = await Location.requestForegroundPermissionsAsync()
  if (fg.status !== 'granted') return 'FOREGROUND_DENIED'
  const bg = await Location.requestBackgroundPermissionsAsync()
  if (bg.status !== 'granted') return 'BACKGROUND_DENIED'
  return null
}

export async function currentPosition(): Promise<{ lat: number; lng: number }> {
  const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
  return { lat: loc.coords.latitude, lng: loc.coords.longitude }
}

export async function startTracking(assignmentId: string): Promise<void> {
  await setKv('assignmentId', assignmentId)
  await setKv('lastFix', null)
  await setKv('mode', 'moving')
  await setKv('lastMovingAt', String(Date.now()))
  await Location.startLocationUpdatesAsync(LOCATION_TASK, taskOptions('moving'))
}

/** Stop the service first so nothing more is recorded, then upload what's left. */
export async function stopTracking(): Promise<void> {
  await setKv('assignmentId', null)
  if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK).catch(() => false)) {
    await Location.stopLocationUpdatesAsync(LOCATION_TASK)
  }
}

export async function isTracking(): Promise<boolean> {
  return Location.hasStartedLocationUpdatesAsync(LOCATION_TASK).catch(() => false)
}

export async function trackingStatus(): Promise<{ running: boolean; queued: number; lastUploadAt: string | null; mode: Mode }> {
  const [running, queued, lastUploadAt, mode] = await Promise.all([isTracking(), queuedCount(), getKv('lastUploadAt'), getKv('mode')])
  return { running, queued, lastUploadAt, mode: (mode as Mode | null) ?? 'moving' }
}

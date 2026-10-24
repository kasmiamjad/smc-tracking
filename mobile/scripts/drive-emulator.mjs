/**
 * Feed the Android emulator a moving GPS position, to test background tracking
 * without leaving the desk. Drives a loop around central Riyadh at ~40 km/h,
 * pausing at "job sites" so the app's stationary back-off can be seen.
 *
 *   node scripts/drive-emulator.mjs            # until Ctrl+C
 *   node scripts/drive-emulator.mjs --minutes 5
 */
import { execFileSync } from 'node:child_process'
import { join } from 'node:path'

const sdk = process.env.ANDROID_HOME ?? join(process.env.LOCALAPPDATA ?? '', 'Android', 'Sdk')
const adb = join(sdk, 'platform-tools', process.platform === 'win32' ? 'adb.exe' : 'adb')
const minutes = Number(process.argv[process.argv.indexOf('--minutes') + 1]) || 0
const stopAt = minutes ? Date.now() + minutes * 60_000 : Infinity

// Corners of a loop through Olaya / King Fahd Rd (lat, lng).
const route = [
  [24.6905, 46.6855],
  [24.7136, 46.6753],
  [24.7255, 46.6585],
  [24.7105, 46.6455],
  [24.6905, 46.6855],
]
const SPEED_MS = 11 // ~40 km/h
const STEP_S = 1
const STOP_EVERY_S = 150 // park for a while every ~2.5 min
const STOP_FOR_S = 150 // longer than the app's 2-minute stationary threshold

const toRad = (d) => (d * Math.PI) / 180
function metres([la1, lo1], [la2, lo2]) {
  const a = Math.sin(toRad(la2 - la1) / 2) ** 2 + Math.cos(toRad(la1)) * Math.cos(toRad(la2)) * Math.sin(toRad(lo2 - lo1) / 2) ** 2
  return 2 * 6371000 * Math.asin(Math.sqrt(a))
}

function fix(lat, lng, speedMs) {
  // geo fix <longitude> <latitude> [altitude [satellites [velocity in knots]]]
  execFileSync(adb, ['emu', 'geo', 'fix', String(lng), String(lat), '600', '10', String(speedMs * 1.94384)], { stdio: 'ignore' })
}

let leg = 0
let t = 0
let elapsed = 0
console.log('Driving the emulator GPS. Ctrl+C to stop.')

while (Date.now() < stopAt) {
  const from = route[leg]
  const to = route[(leg + 1) % route.length]
  const length = metres(from, to)

  const cycle = elapsed % (STOP_EVERY_S + STOP_FOR_S)
  const parked = cycle >= STOP_EVERY_S
  if (!parked) t += (SPEED_MS * STEP_S) / length
  if (t >= 1) {
    t = 0
    leg = (leg + 1) % (route.length - 1)
  }

  const lat = from[0] + (to[0] - from[0]) * t
  const lng = from[1] + (to[1] - from[1]) * t
  fix(lat, lng, parked ? 0 : SPEED_MS)
  if (elapsed % 10 === 0) console.log(`${new Date().toLocaleTimeString()}  ${lat.toFixed(5)}, ${lng.toFixed(5)}  ${parked ? 'parked' : 'driving'}`)

  elapsed += STEP_S
  await new Promise((r) => setTimeout(r, STEP_S * 1000))
}

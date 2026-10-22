const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })
const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

export function formatDateTime(iso: string | null | undefined): string {
  return iso ? dateTime.format(new Date(iso)) : '—'
}

/**
 * "3 hours ago", falling back to a date after a week.
 * `inline` gives sentence-case words for use mid-sentence ("Registered just now").
 */
export function formatRelative(iso: string | null | undefined, inline = false): string {
  if (!iso) return inline ? 'never' : 'Never'
  const seconds = (new Date(iso).getTime() - Date.now()) / 1000
  const abs = Math.abs(seconds)
  if (abs < 60) return inline ? 'just now' : 'Just now'
  if (abs < 3600) return relative.format(Math.round(seconds / 60), 'minute')
  if (abs < 86400) return relative.format(Math.round(seconds / 3600), 'hour')
  if (abs < 604800) return relative.format(Math.round(seconds / 86400), 'day')
  return formatDateTime(iso)
}

/** Clock time in the given zone (shift times are shown in the business timezone). */
export function formatTime(iso: string | null | undefined, timeZone?: string): string {
  if (!iso) return '—'
  // 24-hour, to match how shift templates are written ("08:00–16:00").
  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date(iso))
}

export function formatMinutes(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h}h ${m}m` : `${h}h`
}

export function formatAge(seconds: number | null): string {
  if (seconds === null) return 'No signal yet'
  if (seconds < 60) return `${seconds}s ago`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  return `${Math.floor(seconds / 3600)}h ago`
}

// ---- calendar dates (YYYY-MM-DD strings, no time zone) ----------------------

export function toIsoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function parseIsoDate(s: string): Date {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y!, m! - 1, d!)
}

export function addDays(s: string, days: number): string {
  const d = parseIsoDate(s)
  d.setDate(d.getDate() + days)
  return toIsoDate(d)
}

/** Monday of the week containing `s`. */
export function startOfWeek(s: string): string {
  const d = parseIsoDate(s)
  const iso = d.getDay() || 7
  return addDays(s, 1 - iso)
}

export function formatDayHeading(s: string): { weekday: string; day: string } {
  const d = parseIsoDate(s)
  return {
    weekday: d.toLocaleDateString(undefined, { weekday: 'short' }),
    day: d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
  }
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join('')
}

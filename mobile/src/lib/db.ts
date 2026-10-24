import * as SQLite from 'expo-sqlite'
import { TRACKING } from '../config'

/**
 * Local store shared by the UI and the background location task:
 * - ping_queue: pings waiting to be uploaded (survives no signal and app restarts)
 * - kv: small tracking state (active assignment, interval mode, last upload)
 */
let db: SQLite.SQLiteDatabase | null = null

function open(): SQLite.SQLiteDatabase {
  if (!db) {
    db = SQLite.openDatabaseSync('field-ops.db')
    db.execSync(`
      PRAGMA journal_mode = WAL;
      CREATE TABLE IF NOT EXISTS ping_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT);
    `)
  }
  return db
}

export interface Ping {
  latitude: number
  longitude: number
  accuracy: number | null
  speed: number | null
  heading: number | null
  batteryLevel: number | null
  isCharging: boolean | null
  recordedAt: string
}

export async function enqueuePings(pings: Ping[]): Promise<void> {
  const d = open()
  await d.withExclusiveTransactionAsync(async (tx) => {
    for (const p of pings) {
      await tx.runAsync('INSERT INTO ping_queue (payload) VALUES (?)', JSON.stringify(p))
    }
    // Never let a phone that's offline for days fill its storage.
    await tx.runAsync('DELETE FROM ping_queue WHERE id <= (SELECT MAX(id) FROM ping_queue) - ?', TRACKING.maxQueued)
  })
}

export async function peekPings(limit: number): Promise<{ ids: number[]; pings: Ping[] }> {
  const rows = await open().getAllAsync<{ id: number; payload: string }>('SELECT id, payload FROM ping_queue ORDER BY id LIMIT ?', limit)
  return { ids: rows.map((r) => r.id), pings: rows.map((r) => JSON.parse(r.payload) as Ping) }
}

export async function removePings(ids: number[]): Promise<void> {
  if (!ids.length) return
  await open().runAsync(`DELETE FROM ping_queue WHERE id IN (${ids.map(() => '?').join(',')})`, ...ids)
}

export async function queuedCount(): Promise<number> {
  const row = await open().getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM ping_queue')
  return row?.n ?? 0
}

export async function getKv(key: string): Promise<string | null> {
  const row = await open().getFirstAsync<{ value: string | null }>('SELECT value FROM kv WHERE key = ?', key)
  return row?.value ?? null
}

export async function setKv(key: string, value: string | null): Promise<void> {
  if (value === null) await open().runAsync('DELETE FROM kv WHERE key = ?', key)
  else await open().runAsync('INSERT INTO kv (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', key, value)
}

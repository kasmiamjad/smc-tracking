import Constants from 'expo-constants'

export const API_URL: string = (Constants.expoConfig?.extra?.apiUrl as string | undefined) ?? 'https://smc-tracking.effedo.app/api/v1'

/** Default country code for phone entry (Saudi Arabia). */
export const DEFAULT_COUNTRY_CODE = '+966'

// Spec 4.2: ping every 4s while moving; after 2 minutes stationary back off to 15s.
export const TRACKING = {
  movingIntervalMs: 4_000,
  stationaryIntervalMs: 15_000,
  stationaryAfterMs: 2 * 60_000,
  movingSpeed: 1, // m/s
  maxBatch: 500,
  maxQueued: 30_000, // ~33h at 4s; the server drops pings older than 24h anyway
}

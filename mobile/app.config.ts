import type { ConfigContext, ExpoConfig } from 'expo/config'

/**
 * EXPO_PUBLIC_API_URL picks the backend at build time:
 *   production (default)        https://smc-tracking.effedo.app/api/v1
 *   Android emulator → laptop   http://10.0.2.2:8000/api/v1
 */
const API_URL = process.env.EXPO_PUBLIC_API_URL ?? 'https://smc-tracking.effedo.app/api/v1'
const isLocalHttp = API_URL.startsWith('http://')

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'SMC Field Ops',
  slug: 'smc-field-ops',
  version: '0.1.1',
  orientation: 'portrait',
  icon: './assets/icon.png',
  userInterfaceStyle: 'light',
  android: {
    // Permanent once published to Google Play; change it before the first store upload if needed.
    package: 'app.effedo.smcfieldops',
    versionCode: 2,
    adaptiveIcon: {
      backgroundColor: '#1D4ED8',
      foregroundImage: './assets/android-icon-foreground.png',
      backgroundImage: './assets/android-icon-background.png',
      monochromeImage: './assets/android-icon-monochrome.png',
    },
    // No tracking without location; nothing else is requested.
    permissions: [
      'ACCESS_FINE_LOCATION',
      'ACCESS_COARSE_LOCATION',
      'ACCESS_BACKGROUND_LOCATION',
      'FOREGROUND_SERVICE',
      'FOREGROUND_SERVICE_LOCATION',
      'POST_NOTIFICATIONS',
    ],
    blockedPermissions: ['android.permission.RECORD_AUDIO', 'android.permission.READ_CONTACTS'],
    predictiveBackGestureEnabled: false,
  },
  plugins: [
    [
      'expo-location',
      {
        isAndroidBackgroundLocationEnabled: true,
        isAndroidForegroundServiceEnabled: true,
        locationAlwaysAndWhenInUsePermission: 'SMC Field Ops shares your location with dispatch only while you are checked in to a shift.',
      },
    ],
    'expo-secure-store',
    'expo-sqlite',
    [
      'expo-build-properties',
      {
        android: {
          // Plain HTTP is only allowed for a local development backend.
          usesCleartextTraffic: isLocalHttp,
          // The SDK's default CMake 3.22 bundles ninja 1.10, which fails on Windows paths
          // over 260 characters (React Native codegen nests deeply). 3.31 ships ninja 1.12.
          cmakeVersion: '3.31.6',
        },
      },
    ],
  ],
  extra: { apiUrl: API_URL },
})

# SMC Field Ops — Technician App (Android)

Expo SDK 58 / React Native app for technicians: phone + PIN sign-in, shift check-in/out, and background GPS while on shift.

## What it does

- **Sign-in:** phone + 4-digit PIN, self-registration (account waits for approval), forced change of an admin-issued temporary PIN. Clear messages for wrong PIN (with attempts left), locked, pending, rejected and blocked accounts. Tokens are kept in Android's encrypted storage.
- **Shifts:** the active shift with a running timer, and the next 7 days. Check-in opens 60 minutes before a shift starts.
- **Tracking (spec 4.2):** a foreground service with a persistent notification. It sends a fix every **4 s while moving** and every **15 s after 2 minutes stationary**, with battery level and charging state. Each fix is saved to SQLite first and uploaded in batches of up to 500, so nothing is lost without signal.
- **Privacy (spec 6.2):** tracking runs only between check-in and check-out, and a banner always shows whether location sharing is on. Check-out confirms with the server before tracking stops, so a technician without signal can't end up with an open shift and no tracking.
- **Sessions:** only a server rejection signs the user out (blocked, PIN reset, expired). Losing signal never does.

Not yet: the emergency siren and YES/NO reply (Sprint 4), uploading documents from the phone, iOS.

## Build

Needs Node 20+, Android Studio (SDK and JDK) and `ANDROID_HOME` set.

```bash
npm install
npx expo prebuild --platform android      # generates android/ from app.config.ts (git-ignored)
cd android && ./gradlew assembleRelease   # → android/app/build/outputs/apk/release/app-release.apk
```

`EXPO_PUBLIC_API_URL` chooses the backend at build time:

| Target | Value |
|---|---|
| Production (default) | `https://smc-tracking.effedo.app/api/v1` |
| Emulator → laptop running `php artisan serve` | `http://10.0.2.2:8000/api/v1` |

Plain HTTP is only enabled when the URL starts with `http://`. Run `prebuild --clean` again after changing it.

The release build is currently signed with the debug key, which is fine for test installs. Before uploading to Google Play, create a real upload key and keep it safe: losing it means you can't update the app.

## Test on the emulator

```bash
adb install -r android/app/build/outputs/apk/release/app-release.apk
node scripts/drive-emulator.mjs --minutes 10    # moves the emulator's GPS around Riyadh
```

Sign in as a local technician, check in, and watch the console's live map.

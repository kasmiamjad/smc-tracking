# SMC Field Ops — Backend API

Laravel 13 API for the manpower tracking system (see `../manpower_tracking_technical_spec.md`).

Stack: PHP 8.3 · MySQL 8.4 (spatial) · Redis (cache, rate limits) · Sanctum (bearer tokens) · Reverb (WebSockets).

## Local setup (Laragon)

```bash
composer install
cp .env.example .env
php artisan key:generate
# fill REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET with any random values
# create databases smc_map and smc_map_test (utf8mb4) in HeidiSQL / MySQL
php artisan migrate --seed
php artisan app:create-admin you@example.com --name="Your Name"
```

Run (separate terminals; Laragon's Redis must be running):

```bash
php artisan serve          # API on http://localhost:8000
php artisan reverb:start   # WebSockets on :8080
php artisan queue:work     # delivers broadcasts (e.g. session.revoked)
```

Tests run against the `smc_map_test` MySQL database: `php artisan test`.

## Auth model

| Who | Login | Endpoint |
|---|---|---|
| Technician | phone (E.164) + 4-digit PIN | `POST /api/v1/auth/login-pin` |
| Admin / Dispatcher | email + password | `POST /api/v1/auth/login` |

- Each login returns an **access token** (24h, ability `access`) and a **refresh token** (30d, ability `refresh`). Neither works in place of the other.
- `POST /auth/refresh` with the refresh token rotates both. The old pair stops working.
- Brute force: 5 failed attempts per phone/email per 15 minutes, and 5 consecutive failures lock the account until an admin calls `reset-pin` or `unlock`. Status (pending, blocked, ...) is only revealed after a correct PIN.
- Blocking, rejecting or resetting a PIN deletes all of the user's tokens immediately and broadcasts `session.revoked` on `private-users.{id}`.
- Admin-created technicians get a temporary PIN and must call `POST /auth/change-pin` before any other endpoint (`PIN_CHANGE_REQUIRED`).
- Errors use `{"error": "CODE", "message": "..."}`. Validation errors use Laravel's standard `{"message", "errors"}`.

## Endpoints (Sprint 1: MOD-01 to MOD-06)

Public: `POST auth/register` (multipart, optional `documents[i][file|docType]`), `POST auth/login-pin`, `POST auth/login`, `GET departments`.

Any signed-in user: `GET auth/me`, `POST auth/logout`, `POST auth/refresh`, `POST auth/change-pin`, `GET|POST users/{id}/documents`, `GET documents/{id}/download`, `DELETE documents/{id}`, `POST broadcasting/auth`.

Admin + dispatcher (read): `GET admin/users` (`status, role, departmentId, blocked, search, perPage`), `GET admin/users/pending`, `GET admin/users/{id}`.

Admin only: `POST admin/users`, `PATCH admin/users/{id}`, `PATCH admin/users/{id}/approval`, `PATCH admin/users/{id}/block`, `POST admin/users/{id}/reset-pin`, `POST admin/users/{id}/unlock`, `PATCH admin/documents/{id}/verification`, `POST|PUT|DELETE departments`.

All paths are prefixed with `/api/v1/`.

## Endpoints (Sprint 2: MOD-07 to MOD-11)

Technicians: `GET shifts/my-shifts` (active plus the next 7 days), `POST shifts/check-in` `{assignmentId, location: {lat, lng}}`, `POST shifts/check-out` `{assignmentId, location?}`, `POST telemetry/ping` (the spec 4.2 payload, or `{"pings": [...]}` with up to 500 buffered pings).

Admin + dispatcher (read): `GET shifts`, `GET shifts/roster?from&to&departmentId&userId&status` (default: the current week), `GET admin/fleet/live` (everyone checked in, with latest position and `freshness`: LIVE / LAGGING / OFFLINE).

Admin only: `POST|PUT|DELETE shifts`, `POST shifts/assign` `{shiftId, userIds[] | departmentId, startDate, endDate}`, `DELETE shift-assignments/{id}` (only shifts that haven't started).

Rules:
- Shift times are wall-clock in `SHIFTS_TIMEZONE` (default `Asia/Riyadh`) and stored in UTC. An end time at or before the start time means the shift ends the next day.
- Each assignment saves its own scheduled start and end, so editing a template never changes past timesheets.
- Check-in opens 60 minutes before the start. Being outside the shift's zone is allowed but flagged (`checkInOutsideZone`).
- **Overtime = worked time beyond the scheduled shift length.** Check-out records worked, regular and overtime minutes.
- `app:close-overdue-shifts` (every 5 minutes) checks out shifts left open 4 hours past their end, at the scheduled end time, and marks never-started shifts ABSENT.
- Pings are kept only if recorded while checked in. Buffered pings uploaded after check-out still count toward the shift they were recorded in. `userId` in the body is ignored.
- Latest positions live in `technician_positions` (one row per technician) and are broadcast on `private-fleet` as `position.updated`.

## Endpoints (Sprint 3: MOD-12, MOD-13, MOD-19, MOD-20)

Admin + dispatcher: `GET admin/fleet/trail/{assignmentId}?limit` (breadcrumbs of one shift, thinned to `limit`, newest point always kept), `GET reports/timesheet?period=daily|weekly|monthly|custom&startDate&endDate&departmentId&userId`, `GET reports/timesheet/export?…&exportType=csv|xlsx|pdf`.

`admin/fleet/live` also returns `realtime`: the WebSocket settings the console uses when `BROADCAST_CONNECTION=reverb` (public key only), or `{"enabled": false}`, in which case the console polls every 5 seconds.

Timesheet rules:
- Shifts count toward the date they **started** (Riyadh time), so overnight shifts don't split.
- In-progress shifts count time worked so far and are flagged `inProgress`.
- Daily reports add `movingHours`, `stationaryHours` and `gpsCoverage` from the pings: speed above 1 m/s counts as moving, and gaps over 60 seconds count as missing tracking. Low coverage means someone checked in without tracking (spec 4.6 "ghost attendance").
- Exports: CSV is a single table (day = shifts, week = day grid, month/custom = per technician). Excel adds a "Shifts" sheet. The PDF is a branded A4 landscape printout with a department breakdown and sign-off lines. `COMPANY_NAME` sets the header.
- Arabic names print correctly in the PDF (shaped with ar-php; dompdf can't shape Arabic itself).

## Fake GPS (local only)

```bash
php artisan db:seed --class=DemoSeeder      # sample technicians, once
php artisan serve                           # terminal 1
php artisan app:simulate-fleet --minutes=10 --flaky   # terminal 2
```

This checks demo technicians in to a "Simulation" shift through the real API and sends a ping every 4 seconds as they drive around. `--flaky` drops some pings so pins show as lagging or offline. It refuses to run in production.

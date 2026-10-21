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

# SMC Field Ops — Admin Console

React 19 + Vite + Tailwind single-page app for admins and dispatchers. It talks to the Laravel API in `../backend` and is served by Laravel at `/admin` on the same domain, so no CORS setup is needed.

## Develop

```bash
# terminal 1 (in ../backend)
php artisan serve
# terminal 2 (here)
npm install
npm run dev        # http://127.0.0.1:5173/admin, /api is proxied to :8000
```

For sample data run `php artisan db:seed --class=DemoSeeder` in `../backend`. It adds technicians and a demo admin (credentials are in the seeder) and is local only.

## Build and deploy

```bash
npm run build      # outputs to ../backend/public/admin
```

The build output **is committed**, because the server has no Node.js. Rebuild and commit it with every console change. Deploys are then just `git pull` on the server.

## What's here

| Page | Admin | Dispatcher |
|---|---|---|
| Dashboard: counts, approval queue preview | ✓ | ✓ |
| Approvals: approve / reject with reason, document previews | ✓ | view only |
| Users: search, filter, add technician / dispatcher / admin | ✓ | view only |
| User profile: edit, block / unblock, reset PIN, unlock, documents | ✓ | view only |
| Departments: name and map color | ✓ | view only |

Access tokens refresh automatically. If the refresh token is no longer valid, the user is sent back to sign-in.

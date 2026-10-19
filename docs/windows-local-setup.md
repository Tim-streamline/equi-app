# Windows web development setup

The web app and its local services are installed. No Android SDK, Android Studio,
Java toolchain, or emulator is required or installed for this setup.

## Open the app

- Customer web app: <http://127.0.0.1:3000>
- Laravel admin: <http://127.0.0.1:81/admin>
- PowerSync: <http://127.0.0.1:8080>

The repository's local development seeders created these accounts:

| App | Email | Password |
| --- | --- | --- |
| Customer web | shelleymeeuwsen@gmail.com | password |
| Admin | contact@depaardentherapeut.nl | password |

These accounts and generated demo data belong to the local PostgreSQL database.
Local email is written to Laravel's log.

## Installed and verified

- Node.js 24.20.0 / npm 11.19.0, Docker Desktop 4.95.0, and WSL 3.0.1.
- Sail PHP 8.5.11 and FrankenPHP 1.13.0.
- Locked Composer and web/admin npm dependencies.
- PostgreSQL 18, MongoDB 7, and PowerSync; migrations and development seeders.
- Application encryption key, PowerSync signing keys, browser push VAPID keys,
  storage link, queue worker, and scheduler.
- Web TypeScript check, all 27 web tests, and both frontend production builds.
- All 16 backend WebSession tests passed with 147 assertions against the separate
  testing database.
- Real browser-session login/logout and authenticated home API returned HTTP 200.
- The admin page, PowerSync's proxied health endpoint, and Laravel JWKS endpoint
  returned HTTP 200; replication logs showed zero lag.

## Start after reboot

Start Docker Desktop first. From `backend/`:

```powershell
docker compose up -d --no-build --wait

docker compose exec -d -u sail laravel.test php artisan queue:work --sleep=3 --tries=1 --timeout=90
docker compose exec -d -u sail laravel.test php artisan schedule:work
```

Run each detached worker command once per container start to avoid duplicates.
The setup started both workers already; they need restarting if the Laravel
container is stopped or recreated.

From `web/`, choose the development server for live edits:

```powershell
npm.cmd start
```

Or serve the already-built frontend:

```powershell
npm.cmd run preview
```

A production preview is currently serving port 3000. Stop that process before
starting another server on the same port. Its last recorded PID is in
`%LOCALAPPDATA%\EquiAppSetup\web-preview.pid`; verify the process identity before
stopping it. The preview does not start automatically after reboot. Open port
3000 in the browser; Metro's port 8082 is behind the web proxy.

## Local configuration

- `backend/.env`: PostgreSQL at `pgsql:5432`, database `equiapp`, Sail database
  user, backend port 81, and customer web URL `http://127.0.0.1:3000`.
- `web/.env`: localhost backend/PowerSync targets and same-origin browser access.
  Do not copy the mobile app's environment into `web/`.
- Private signing keys are in `backend/storage/app/private/`; VAPID and
  application keys are in the ignored backend environment file.
- Backend error reporting is disabled locally; `MAIL_MAILER=log` keeps local
  email in the Laravel log.

This laptop has approximately 8 GB RAM. `%USERPROFILE%\.wslconfig` limits its WSL
VM to 2 GB RAM and four processors, with WSL swap disabled. These settings apply
to all WSL distributions; Docker was the only installed distribution when they
were configured. See [Microsoft's WSL configuration reference](https://learn.microsoft.com/en-us/windows/wsl/wsl-config).

The ignored `backend/compose.override.yaml` limits Octane to two PHP workers and
PowerSync's JavaScript heap to 512 MB. The override is excluded using this
checkout's `.git/info/exclude`; it is a local machine setting.

Free disk space remains limited; the final check found about 2.8 GB free while
all services were running. The setup cleared its temporary Docker build cache
and npm download cache; it did not delete personal files. The saved
`sail-8.5/app` image is usable, so rebuilding it is unnecessary for normal startup.

## Shared browser dependencies

The web app imports source from `expo-app/`. An ignored directory junction from
`expo-app/node_modules` to `web/node_modules` lets TypeScript and the admin build
resolve that shared source without a separate native dependency installation.
A second junction exposes Expo's nested `expo-file-system` package for TypeScript.

If reinstalling frontend dependencies, run `npm.cmd ci` from `web/` and recreate
any missing links from the repository root:

```powershell
if (!(Test-Path expo-app/node_modules)) {
    New-Item -ItemType Junction -Path expo-app/node_modules -Target (Resolve-Path web/node_modules).Path
}
if (!(Test-Path web/node_modules/expo-file-system)) {
    New-Item -ItemType Junction -Path web/node_modules/expo-file-system -Target (Resolve-Path web/node_modules/expo/node_modules/expo-file-system).Path
}
```

Remove the shared dependency junction before installing a separate native app
dependency tree in `expo-app/`.

## Build and check

From `web/`:

```powershell
npm.cmd run typecheck
npm.cmd test
npm.cmd run build
```

From `backend/`:

```powershell
npm.cmd exec -- vite build
docker compose exec -u sail laravel.test php artisan octane:reload
docker compose exec -u sail laravel.test php artisan migrate:status
docker compose exec -u sail laravel.test php artisan test --compact tests/Feature/WebSessionTest.php
```

The backend's normal `npm run build` includes a Bash Sail reload helper. In
PowerShell, use Vite and the explicit reload command shown above.

## Stop

Stop a foreground frontend with Ctrl+C. From `backend/`, stop containers with:

```powershell
docker compose stop
```

This preserves the local database volumes. The detached queue/scheduler workers
stop with the Laravel container and must be started again after it restarts.

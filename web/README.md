# EquiApp web

Browser version of the Expo app, with shared routes, screens, assets, and business logic. Run commands from this directory. Requires Node 22.18+ (or Node 24 LTS) and the existing Laravel/PostgreSQL/PowerSync services.

## Develop

```sh
npm ci
cp .env.example .env
npm start
```

Open http://127.0.0.1:3000. The server starts Expo on port 8082 and proxies API requests, browser sessions, private media, and PowerSync streams through port 3000. Use port 3000, not Metro directly. Start the backend by running `./vendor/bin/sail up -d` from `../backend`.

`BACKEND_URL`, `POWERSYNC_URL`, `HOST`, `PORT`, and `METRO_PORT` can be set in `.env` or the process environment. Do not copy the mobile app's `.env`: browser URLs are same-origin and its native API address is unnecessary.

## Build and run

```sh
npm run build
npm run preview
```

`dist/` contains the production SPA, fonts, images, and PowerSync SQLite workers/WASM. The preview server supports direct links and refreshes, API proxying, private media, and PowerSync HTTP/WebSocket streams. For hosting, run this server behind an HTTPS reverse proxy with WebSocket forwarding. Set Laravel's session cookie to Secure in production, and keep the browser session cookie scoped to the browser-facing host. Reload long-running Laravel workers after deploying the backend changes.

If serving `dist/` with another web server, serve real files first and fall back to `index.html` for routes. Proxy `/api/*`, `/web-session/*`, and `/storage/*` to Laravel. Serve `/powersync/worker/*` and `/powersync/*.wasm` as static assets; proxy the remaining `/powersync/*` requests to PowerSync with the prefix removed, including WebSocket upgrades. Disable buffering for sync streams. WASM needs `application/wasm`.

## Shared code and web adaptations

- `scripts/prepare-routes.mjs` generates small route wrappers in the ignored `app/` directory from `../expo-app/app`. It runs before start/build. Edit the original Expo route; restart after adding/removing route files.
- `metro.config.js` resolves shared source against this project's dependencies and substitutes browser database, authentication, and private-media modules. Native builds retain their existing adapters.
- `db/provider.tsx` uses PowerSync Web with SQLite in IndexedDB, isolated by account and browser tab. Existing SQL selectors and queued writes are reused. Database filenames are hashed to stay within WA-SQLite's path limit.
- Laravel `/web-session/*` endpoints use an HttpOnly session cookie and CSRF protection. Short-lived API/sync tokens remain in memory; passwords are never written to browser storage. Session changes notify other tabs, and logout clears the active tab's database.
- Successful browser login (including registration completion) persists for 30 days using encrypted HttpOnly cookies, even after the shorter server session expires. This is a fixed deadline from the last successful login in that browser; browsing and token renewal do not extend it. Signing in again starts a new 30-day period. Logout, password reset, or a disabled account prevents renewal. Still-valid sessions from before this change upgrade automatically on their next authenticated request, with a one-time 30-day period starting then because legacy sessions did not record the original login time. Already-expired sessions still require login. The admin session lifetime remains separate.
- Browser alerts implement the existing confirmation flows, uploads use browser `File` objects, and private images/videos use a session-protected endpoint with the same Community visibility policy.

Synced database edits can queue while an open app is offline and upload after reconnection. A fresh page load requires an online session check, including when opened from a home-screen shortcut. Remote media is not cached for offline use. Browser push notifications are not implemented; other existing feature behavior follows the Expo app.

## Web icons

`public/` contains browser favicons (ICO and 16/32px PNG), the Apple touch icon, Android/desktop manifest icons, a maskable icon, and the Windows tile icon. All use the existing native EquiApp logo from `../expo-app/assets/images/icon.png`. The maskable version adds space around the logo so platform cropping preserves the lettering. `public/index.html` is the Expo SPA template that links these assets; `site.webmanifest` supplies the home-screen name and icons. The deployment package includes these files. Home-screen shortcuts still require an online page load.

## Validate

```sh
npm run typecheck
npm test
npm run build
```

Backend regressions (run from `../backend`):

```sh
./vendor/bin/sail test --compact tests/Feature/WebSessionTest.php tests/Feature/HomePreferencesTest.php tests/Feature/CommunityTest.php
```

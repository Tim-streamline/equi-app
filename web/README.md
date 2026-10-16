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

### LAN access

Use HTTPS for LAN browsing: the browser database requires secure-context APIs that are unavailable over HTTP on a LAN IP. Keep `HOST=127.0.0.1` for local HTTP access and add `HTTPS_HOST=192.168.1.209` to `.env` to enable a separate HTTPS listener on that IP, also on port 3000.

Generate a development certificate using an existing local mkcert authority:

```sh
mkdir -p .certs
mkcert -cert-file .certs/lan.pem -key-file .certs/lan-key.pem 192.168.1.209 localhost 127.0.0.1
```

Restart `npm start`, then open `https://192.168.1.209:3000` from the same LAN. Each client device must trust the public `rootCA.pem` from `mkcert -CAROOT`. Never copy the CA private key to clients. `.certs/` is ignored by Git; alternative certificate paths can be set with `HTTPS_CERT` and `HTTPS_KEY`. Local access stays at `http://localhost:3000`. If the LAN IP changes, update `HTTPS_HOST` and regenerate the certificate.

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

Synced database edits can queue while an open app is offline and upload after reconnection. A fresh page load requires an online session check, including when opened from a home-screen shortcut. Remote media is not cached for offline use. Browser push uses the Push API and a notification-only service worker; other existing feature behavior follows the Expo app.

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

## Browser notifications and intake access

Browser push covers intake inactivity, protocol-phase availability, and purchased-credit expiry. It is enabled by default under Account → notification preferences, while an explicit opt-out is preserved. Browsers with notification permission already granted subscribe automatically; otherwise users grant browser permission using the settings button. It requires HTTPS (localhost is allowed); iPhone/iPad users must install the web app on the Home Screen first. The service worker does not cache app pages or private responses. SSE is unsuitable here because notifications must arrive after the tab closes.

Opting out immediately invalidates pending enrollment in the page. Subscription changes and worker binding are serialized so removal runs after any registration already in flight. Background enrollment never changes the saved preference; only an explicit enable action can opt back in. Logout uses the same cleanup without changing the user's saved preference.

Deployment prerequisites:

- Run Composer install and Laravel migrations, then build the web app. Keep `/push-sw.js` at the origin root and serve it as JavaScript with revalidation, not the SPA fallback.
- Configure `WEB_PUSH_PUBLIC_KEY`, `WEB_PUSH_PRIVATE_KEY`, and `WEB_PUSH_SUBJECT` (`mailto:` contact or HTTPS URL) in Laravel. Generate one VAPID pair using `Minishlink\WebPush\VAPID::createVapidKeys()` in a secure administrative shell. Keep the private key server-side and retain the same pair across deployments; rotating it requires browsers to subscribe again.
- Keep the Laravel scheduler running. `intakes:send-reminders` runs every 15 minutes; `notifications:send-browser-reminders` runs every minute. Native Expo reminder schedules remain separate. Both obey account preferences; intake/protocol reminders additionally obey the protocol reminder setting.
- Reload long-running backend workers after configuration changes. Missing VAPID configuration disables browser delivery and shows a clear setup message when enabling notifications.

Subscriptions are encrypted, bound to the signed-in account, removed when expired, and unsubscribed on logout. The worker rejects deliveries for a different or signed-out account. Browser deliveries have independent deduplication from native Expo tickets. Provider acceptance is not proof that an OS displayed a notification: verify one real subscribed browser per supported platform before rollout.

A single active subscription unlocks the app; expired accounts see only renewal. The shared gate also enforces synced expiry dates offline, and backend middleware rechecks access. Library credits remain in use. A protocol is purchased separately for a horse and comes with exactly one intake. Backend **Protocol kopen** configures the price for new purchases (default €147). `ProtocolPurchases::prepare()` snapshots it; a future trusted payment adapter must call `fulfillVerifiedPayment()` only after verifying the payment. Checkout and online renewal remain unavailable until payment integration exists. Legacy paid plan slugs are accepted with identical access; old included-intake grants no longer grant or restrict purchases. Deploy the matching PowerSync rules: feature streams require the signed `subscribed` claim, whose token expiry is capped at the current subscription boundary.

Intake photos are regular CMS fields in the first section, “Intake fotos”. The deployment migration moves existing fields and retains their original answer/attachment namespace in `answer_section_key`, preserving uploads and queued offline edits. New photo questions use the standard section renderer and validation. Earlier `__photos` metadata remains available in historical admin reviews.

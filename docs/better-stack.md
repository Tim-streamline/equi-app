# Better Stack error tracking

Applications in team **607744**:

| App | Platform | Better Stack application |
| --- | --- | --- |
| Customer web app and admin | React / browser JavaScript | [Equi App Web](https://errors.betterstack.com/team/t607744/applications/2781654/data-ingestion) |
| Android | React Native, including native crashes | [Equi App Android](https://errors.betterstack.com/team/t607744/applications/2781655/data-ingestion) |
| Backend, queue and console | Laravel / PHP | [Equi App Backend](https://errors.betterstack.com/team/t607744/applications/2781659/data-ingestion) |

The official Sentry SDKs send events to Better Stack. The default public client DSNs
are write-only ingestion identifiers; they do not grant account access.

## Runtime configuration

Clients accept `EXPO_PUBLIC_BETTER_STACK_DSN`, `EXPO_PUBLIC_BETTER_STACK_ENVIRONMENT`
and `EXPO_PUBLIC_BETTER_STACK_RELEASE`. An explicitly empty DSN disables reporting.
Development clients are disabled unless `EXPO_PUBLIC_BETTER_STACK_ENABLED=true`.
EAS preview builds use `staging`, production builds use `production`.
Android errors include the Expo update ID, runtime version and channel.

The admin accepts equivalent `VITE_BETTER_STACK_*` variables. Backend runtime uses
`BETTER_STACK_DSN`, `BETTER_STACK_ENVIRONMENT` and optional `BETTER_STACK_RELEASE`.
Backend releases otherwise come from `RELEASE_ID`. The deployment command injects
the target environment and release into both frontend builds.

Run `backend/vendor/bin/sail artisan sentry:test` to send a synthetic backend test.
Automated tests disable external ingestion and exercise a fake transport instead.

## Source maps and native builds

`SENTRY_AUTH_TOKEN` is a **private Telemetry API token**, never a public Expo/Vite
variable. The existing account token is saved locally in the ignored,
mode-600 `deploy/betterstack.env`. `deploy-to` reads it for isolated frontend builds;
it is excluded from deployment packages and Laravel runtime configuration.

The web build generates and uploads source maps and then removes maps from `dist`.
An exact copy of the web bundle/map is retained privately in `web/.source-maps`.
Admin builds use the Sentry Vite plugin. Upload endpoint:
`https://us-west-2a-sourcemaps.betterstackdata.com/`, organization `607744`,
project `2781654` for web/admin and `2781655` for Android.

Android requires a **new native build** after this SDK addition. Run
`npx expo prebuild --platform android --no-install` before local native builds.
For EAS builds/updates, configure `SENTRY_AUTH_TOKEN` as a secret in the relevant
EAS project environments. The config plugin uploads native-build JavaScript maps
when the token is available; OTA update scripts also upload their maps.
Without an upload token errors are still captured, but release stack traces may
remain minified. Existing APKs cannot acquire native crash tracking via an OTA update.

Better Stack currently supports JavaScript maps but does not accept native debug
symbols or Android ProGuard/R8 mappings. Native crashes are captured; obfuscated
native frames can remain unreadable.

## Data policy

No performance tracing, session replay, screenshots, view hierarchies or remote log
collection is enabled. JavaScript and PHP events omit user details, request bodies,
request headers, cookies, query strings, breadcrumbs and extra data. JavaScript
events also omit arbitrary application context. Native crashes use the SDK's
default PII restrictions and may include device/OS diagnostic context.
Email addresses, bearer/JWT tokens and credential assignments in error text are
redacted; backend query exceptions omit interpolated SQL. Stack traces, error type,
release/environment and operation tags remain available.

Background synchronization now explicitly reports credential, upload, download and
retry failures. Intake attachments also report storage failures and files still
pending after three minutes on screen. Events contain only a fixed operation name,
failure category and HTTP status when available; raw exception messages, response
bodies, filenames, photo bytes, answers and account identifiers are not sent.
Repeated operation/category/status combinations are limited to one event per five
minutes per running app. Deliberate cancellation during retry is not reported.

Pending attachments offer **Upload opnieuw proberen**. This cancels outstanding
sync requests and reconnects without clearing local answers, the CRUD queue or
attachment storage. Credential requests time out after 20 seconds, answer uploads
after 30 seconds and individual file uploads after two minutes. A retry starts
background synchronization; **Geüpload** appears only after the file upload is
confirmed and persisted locally. The existing same-tab **Bewaren en vernieuwen**
flow preserves queued photos across a web update.

The account's default data region is **United States** and retention is **90 days**.
Changing region requires a plan upgrade in this account.

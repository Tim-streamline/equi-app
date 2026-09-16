# Equi App mobile

Expo SDK 54 / React Native app, connected to the `@optimize-it/equi-app` EAS project.

## Install and develop

```sh
npm ci
npx expo start
```

The checked-in `.npmrc` preserves the existing PowerSync/op-sqlite dependency combination.
Set `EXPO_PUBLIC_API_BASE_URL` in `.env.local` to a backend reachable from the device.

## Over-the-air updates

EAS Update delivers compatible JavaScript, styles, and bundled assets without reinstalling the app. Each device first needs a native build containing `expo-updates`. Older installed builds cannot acquire this capability through an update.

The app checks for updates on launch, downloads in the background, and applies a downloaded update on the next cold launch. It does not interrupt the current screen or require the user to press an update button. The embedded bundle remains available offline.

| Channel | Build | EAS environment |
| --- | --- | --- |
| `preview` | Local release builds and EAS `preview` APKs | `preview` |
| `production` | EAS `production` builds | `production` |
| `development` | EAS development client; Metro during development | `development` |

`app.json` supplies the `preview` channel for direct local Gradle builds. EAS Build uses the channel declared by its profile in `eas.json`.

### Backend configuration

Set `EXPO_PUBLIC_API_BASE_URL` separately in each EAS environment **before building or publishing for that environment**. Builds and updates must use the same intended backend. Preview may use a reachable local backend; production must use the actual hosted HTTPS backend. Do not copy a local address into the production environment.

```sh
npx --yes eas-cli@latest env:create preview \
  --name EXPO_PUBLIC_API_BASE_URL --value '<preview-backend-url>' \
  --visibility plaintext --scope project

npx --yes eas-cli@latest env:create production \
  --name EXPO_PUBLIC_API_BASE_URL --value '<production-https-backend-url>' \
  --visibility plaintext --scope project
```

Use `eas env:update` for an existing variable. `EXPO_PUBLIC_*` values are bundled into the app and must never contain secrets.

### Publish a JavaScript or asset change

From `expo-app/`:

```sh
npm test
npx tsc --noEmit
npm run lint
npm run update:preview -- --message "Describe the change"
```

Close and reopen the preview app to download the update, then close and reopen it again to apply it. Verify the changed behavior and backend connection before publishing to production:

```sh
npm run update:production -- --message "Describe the verified change"
```

These scripts publish Android updates. iOS needs its own compatible native build and device verification before publishing iOS updates.

Project and update history: https://expo.dev/accounts/optimize-it/projects/equi-app/updates

### Native changes and the first update-enabled build

The `fingerprint` runtime policy derives compatibility from native dependencies and configuration. Adding a native module, upgrading Expo/React Native, changing native configuration, or changing the app version can produce a new runtime. Install a new binary for that runtime; an OTA update cannot add native code to an older binary.

For a local preview build, first load the preview backend variables and regenerate Android:

```sh
npx --yes eas-cli@latest env:pull preview --path .env.local
npx expo prebuild --platform android --no-install
npx expo run:android --variant release --device
```

For EAS builds:

```sh
npx --yes eas-cli@latest build --profile preview --platform android
npx --yes eas-cli@latest build --profile production --platform android
```

EAS builds also require appropriate signing configuration. Local APKs retain the existing local signing setup. This update setup does not publish anything to an app store.

Do not bump the app version for a JavaScript-only OTA release. The existing `release.sh` bumps the version after installation by default; use `--no-bump` for a build that will receive subsequent OTA updates, and run prebuild first after native configuration changes.

### Verify and recover

Android startup logs include `[updates] launch` with the channel, runtime version, update ID, and `isEmbeddedLaunch`. A downloaded update has `isEmbeddedLaunch: false`; compare its update ID with the EAS update details. This log contains no credentials or user data.

If an update causes a problem, use the EAS dashboard's rollback controls or `npx --yes eas-cli@latest update:rollback`. Target the affected channel and runtime. Rollback also reaches devices through the update/restart cycle.

References: [EAS Update setup](https://docs.expo.dev/eas-update/getting-started/), [runtime compatibility](https://docs.expo.dev/eas-update/runtime-versions/), [SDK 54 updates](https://docs.expo.dev/versions/v54.0.0/sdk/updates/).

# Android and web builds — 2026-09-28 evening

Built the current working tree, including OPT-62–67 and the existing thumbnail-cache changes. No staging web/backend deployment was requested or performed.

- Android: `JAVA_HOME=/home/tim/Applications/android-studio/jbr bash ./gradlew assembleRelease --console=plain` succeeded in 37 seconds.
- APK: `expo-app/android/app/build/outputs/apk/release/app-release.apk`.
- SHA-256: `8c59a3746324d01b8e93cedd96ddeabe393a31ecbb0c02619d7dfceacc806529`.
- Version: 0.0.8 / code 12, unchanged for this deployment.
- Web: `npm run build` in `web/` succeeded; output `web/dist/`.
- API configuration: `http://192.168.1.209:81`, matching the host's current LAN address.

## Devices

Pixel 7 (`panther`, wireless serial `adb-28061FDH200HZQ-phpiAj._adb-tls-connect._tcp`): `adb install -r` succeeded, lastUpdateTime 2026-09-28 22:11:50. Launch returned Status ok / MainActivity. Phone-to-backend `/up` returned HTTP 200. The phone subsequently showed the lock screen; foreground app UI, authenticated session, and completed sync could not be verified. No fatal app crash was found in the captured process logs.

Pixel 7 Pro (`cheetah`, discovered at `192.168.1.125:37357`): `adb install -r` succeeded, lastUpdateTime 2026-09-28 22:11:53. Cold launch returned Status ok / MainActivity. Logs showed embedded launch, successful token acquisition, PowerSync connected:true and DidCompleteSync. Phone-to-backend `/up` returned HTTP 200. The user switched to another app before visual feature verification; no further navigation was attempted. No fatal app crash was found in the captured process logs.

Both installations preserved application data. No version bump, app-data reset, or tablet installation was performed.

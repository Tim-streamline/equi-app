# Android and web build — 27 September 2026

- Android: `assembleRelease` succeeded; version 0.0.8, versionCode 12 (unchanged).
- APK: `expo-app/android/app/build/outputs/apk/release/app-release.apk` (133 MB).
- SHA-256: `3d289b1feecfc6ef6c0a9899c4e0498f155f9a3ee69002973ac0d4f6bcf7e6a3`.
- Web: production export succeeded in `web/dist`; local wrapper at `http://127.0.0.1:3000` serves it and `/plus` returned HTTP 200. No remote web deployment was requested or performed.
- Both physical models were verified before `adb install -r`; both installations returned Success, preserving app data.
- Pixel 7 (`panther`): installation updated at 19:46:43 local time, launch returned Status: ok. Phone-to-backend `/up` returned HTTP 200. The phone remained locked, so foreground UI, authentication and sync were not verified.
- Pixel 7 Pro (`cheetah`): installation updated at 19:46:42 local time, launch returned Status: ok, MainActivity foreground. Phone-to-backend `/up` returned HTTP 200; existing credentials minted a token, PowerSync connected and DidCompleteSync was logged. The new Ontdek Plus screen was observed on the device. No fatal startup exception appeared in the focused logs. Embedded launch was confirmed.

The build includes the current working tree, including the credit/library/Plus changes. Neither device was reset and version files were not changed.

# OPT-68 / OPT-69 build and device deployment — 2026-09-28

Applied the two confirmed copy changes to the current working tree, preserving existing changes:
- OPT-68: removed the permanence sentence from the unlock confirmation.
- OPT-69: restored the exact supplied library-retention and Basic-access paragraph.
- Updated the existing unlock interaction test to expect the removed sentence to be absent.

## Validation and artifacts

- Existing credits-screen and library-details tests: 18 passed.
- `git diff --check`: passed.
- `npm run build` in `web/`: passed. Output: `web/dist/`.
- Verified exported web JavaScript contains the corrected paragraph and excludes the removed sentence.
- Android `assembleRelease`: BUILD SUCCESSFUL in 20s.
- APK: `expo-app/android/app/build/outputs/apk/release/app-release.apk`.
- APK SHA-256: `b4bd9abc58e5a7de222d5261c6a61247c456afacc930f6f9867f3134bd29acf6`.
- Version remains 0.0.8 / code 12.
- Host backend `/up` and PowerSync `/probes/liveness`: HTTP 200.
- Web production output was built locally; no remote web deployment performed.

## Devices

Installed with `adb install -r`, preserving app data, once per physical device despite duplicate ADB connections. All installs returned Success and launches returned Status: ok.

| Device | Hardware serial | lastUpdateTime | Verification |
| --- | --- | --- | --- |
| Pixel 7 | 28061FDH200HZQ | 2026-09-28 23:00:19 | App process running; lock/notification shade prevents visual verification. |
| Pixel 7 Pro | 32281FDH30005Z | 2026-09-28 23:00:32 | MainActivity foreground; embedded build launched; authenticated credits screen displays the complete corrected paragraph. PowerSync connected:true observed; fresh completed sync not established by captured logs. |
| Nokia T21 | PP19616CA13C0200128 | 2026-09-28 23:00:36 | MainActivity foreground; embedded build launched. Saved login rejected with HTTP 401 Invalid credentials; sign-in required. |

Pixel 7 Pro credits screen verified using `expoapp://account/credits` and UI hierarchy capture. Unlock confirmation behavior was covered by the existing interaction tests; no credits were spent during device verification.

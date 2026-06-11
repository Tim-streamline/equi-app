#!/usr/bin/env bash
#
# release.sh — build a release APK and deploy it to the connected device.
#
# Builds the Android release variant (JS bundle is produced by the Gradle React
# plugin during assembleRelease), then installs and launches it on the single
# device/emulator currently attached over adb.
#
# Versioning: the current version (app.json -> expo.version, mirrored in
# android/app/build.gradle) is what gets built and deployed. After a successful
# deploy the patch version is bumped, so the *next* run ships the next version.
# Baseline is 0.0.0, i.e. the first run deploys v0.0.0 and leaves 0.0.1 staged.
#
# Usage:
#   ./release.sh             build, install, launch, then bump patch version
#   ./release.sh --no-launch  build and install only (still bumps)
#   ./release.sh --no-bump    do not bump the version afterward

set -euo pipefail

cd "$(dirname "$0")"

PACKAGE="com.anonymous.expoapp"
APK="android/app/build/outputs/apk/release/app-release.apk"
GRADLE="android/app/build.gradle"
APP_JSON="app.json"
LAUNCH=1
BUMP=1
for arg in "$@"; do
  case "$arg" in
    --no-launch) LAUNCH=0 ;;
    --no-bump)   BUMP=0 ;;
    *) echo "error: unknown argument '$arg'"; exit 1 ;;
  esac
done

# --- locate adb -------------------------------------------------------------
ADB="$(command -v adb || true)"
if [[ -z "$ADB" ]]; then
  for d in "${ANDROID_HOME:-}" "${ANDROID_SDK_ROOT:-}" "$HOME/Android/Sdk"; do
    [[ -n "$d" && -x "$d/platform-tools/adb" ]] && ADB="$d/platform-tools/adb" && break
  done
fi
[[ -z "$ADB" ]] && { echo "error: adb not found (set ANDROID_HOME or add adb to PATH)"; exit 1; }

# --- require exactly one connected device -----------------------------------
DEVICES=$("$ADB" devices | awk 'NR>1 && $2=="device" {print $1}')
COUNT=$(echo -n "$DEVICES" | grep -c . || true)
if [[ "$COUNT" -eq 0 ]]; then
  echo "error: no device connected. Plug in a device (USB debugging on) or start an emulator."
  exit 1
elif [[ "$COUNT" -gt 1 ]]; then
  echo "error: multiple devices connected:"; echo "$DEVICES"
  echo "Disconnect all but one (this script targets a single device)."
  exit 1
fi
DEVICE="$DEVICES"
echo "==> Target device: $DEVICE"

# --- read current version ---------------------------------------------------
VERSION=$(node -p "require('./app.json').expo.version")
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "error: bad version '$VERSION' in app.json"; exit 1; }
echo "==> Building & deploying v$VERSION"

# --- build ------------------------------------------------------------------
(cd android && ./gradlew assembleRelease)
[[ -f "$APK" ]] || { echo "error: expected APK not found at $APK"; exit 1; }

# --- deploy -----------------------------------------------------------------
echo "==> Installing $APK"
"$ADB" -s "$DEVICE" install -r "$APK"

if [[ "$LAUNCH" -eq 1 ]]; then
  echo "==> Launching $PACKAGE"
  "$ADB" -s "$DEVICE" shell monkey -p "$PACKAGE" -c android.intent.category.LAUNCHER 1 >/dev/null
fi

echo "==> Deployed v$VERSION"

# --- bump patch version for next time ---------------------------------------
if [[ "$BUMP" -eq 1 ]]; then
  IFS=. read -r MAJ MIN PAT <<< "$VERSION"
  NEXT="$MAJ.$MIN.$((PAT + 1))"
  CODE=$(grep -oE 'versionCode[[:space:]]+[0-9]+' "$GRADLE" | grep -oE '[0-9]+')
  NEXT_CODE=$((CODE + 1))

  sed -i "s/\"version\": \"$VERSION\"/\"version\": \"$NEXT\"/" "$APP_JSON"
  sed -i "s/versionName \"$VERSION\"/versionName \"$NEXT\"/" "$GRADLE"
  sed -i "s/versionCode $CODE/versionCode $NEXT_CODE/" "$GRADLE"

  echo "==> Bumped version: $VERSION -> $NEXT (versionCode $CODE -> $NEXT_CODE). Next run ships v$NEXT."
fi

echo "==> Done."

#!/usr/bin/env bash
set -euo pipefail
[[ "$PWD" == /build/web && ! -e .env && -d ../expo-app ]] || { echo 'Run this through ./deploy-staging' >&2; exit 1; }
export CI=1 EXPO_NO_TELEMETRY=1
npm ci --no-audit --no-fund
npm run build
test -s dist/index.html
test -d dist/_expo/static/js/web
test -d dist/powersync/worker
find dist -type f -name '*.wasm' -print -quit | grep -q .

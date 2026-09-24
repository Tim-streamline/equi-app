#!/usr/bin/env bash
set -euo pipefail
repo="${1:?repository required}"
destination="${2:?empty destination required}"
mkdir -p "$destination"
[[ -z "$(find "$destination" -mindepth 1 -print -quit)" ]] || { echo 'Web package destination must be empty' >&2; exit 1; }
mkdir "$destination/web" "$destination/expo-app"
# Explicit source lists exclude .env, native projects/credentials, caches and installed dependencies.
rsync -a --safe-links --exclude='.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.map' \
    "$repo/web/"{components,db,scripts,app.json,package.json,package-lock.json,babel.config.js,metro.config.js,tailwind.config.js,tsconfig.json,global.css,nativewind-env.d.ts} "$destination/web/"
rsync -a --safe-links --exclude='.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.map' \
    "$repo/expo-app/"{app,assets,components,constants,db,hooks,lib,package.json,app.json,tailwind.config.js,tsconfig.json,global.css,nativewind-env.d.ts} "$destination/expo-app/"

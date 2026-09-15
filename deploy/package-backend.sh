#!/usr/bin/env bash
set -euo pipefail
source_dir="${1:?backend source required}"
destination="${2:?empty destination required}"
mkdir -p "$destination"
[[ -z "$(find "$destination" -mindepth 1 -print -quit)" ]] || { echo 'Package destination must be empty' >&2; exit 1; }
rsync -a --safe-links --exclude='cache/*' --exclude='build/' --exclude='hot' --exclude='storage' \
    --exclude='.env*' --exclude='.git' --exclude='.DS_Store' \
    --exclude='*.sqlite*' --exclude='*.db' --exclude='*.dump' --exclude='*.pem' --exclude='*.key' \
    "$source_dir/"{app,bootstrap,config,database,public,resources,routes,artisan,composer.json,composer.lock,package.json,package-lock.json,vite.config.js} \
    "$destination/"

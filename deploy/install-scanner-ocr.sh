#!/usr/bin/env bash
# One-time server dependency. No external OCR service or provider credentials.
set -euo pipefail
if [[ "$(id -u)" != 0 ]]; then
    echo 'Run with sudo: sudo bash deploy/install-scanner-ocr.sh' >&2
    exit 1
fi
apt-get update
apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-nld tesseract-ocr-eng
tesseract --version
tesseract --list-langs

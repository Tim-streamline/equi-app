#!/usr/bin/env bash
# Run on opt-staging as ploi. Uses Ubuntu packages without changing the system.
set -euo pipefail
site=/home/ploi/equi-app.staging.optimize-it.nl
[[ "$(id -un)" == ploi && -d "$site/shared" ]] || { echo 'Run on opt-staging as ploi' >&2; exit 1; }
tools_dir="$site/shared/tools"
packages="$tools_dir/ffmpeg-packages"
mkdir -p "$packages/partial"
apt-get -o Debug::NoLocking=1 -o "Dir::Cache::archives=$packages" \
    --download-only --no-install-recommends --assume-yes install ffmpeg > "$tools_dir/ffmpeg-download.log" 2>&1
runtime="$(mktemp -d "$tools_dir/ffmpeg-runtime-XXXXXX")"
for package in "$packages"/*.deb; do
    dpkg-deb -x "$package" "$runtime"
    dpkg-deb -f "$package" Package Version >> "$runtime/packages.txt"
done
library_path="$runtime/usr/lib/x86_64-linux-gnu:$runtime/usr/lib/x86_64-linux-gnu/pulseaudio:$runtime/usr/lib/x86_64-linux-gnu/blas:$runtime/usr/lib/x86_64-linux-gnu/lapack"
LD_LIBRARY_PATH="$library_path" "$runtime/usr/bin/ffmpeg" -version > "$runtime/version.txt"
head -3 "$runtime/version.txt"
# Validate actual encoding, not just dynamic-library loading.
LD_LIBRARY_PATH="$library_path" "$runtime/usr/bin/ffmpeg" \
    -nostdin -v error -f lavfi -i color=c=black:s=32x32 -frames:v 1 "$runtime/smoke.jpg"
test -s "$runtime/smoke.jpg"
ln -s "$runtime" "$tools_dir/.ffmpeg-current-$$"
mv -Tf "$tools_dir/.ffmpeg-current-$$" "$tools_dir/ffmpeg-current"
cat > "$tools_dir/.ffmpeg-wrapper-$$" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
runtime="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/ffmpeg-current" && pwd -P)"
export LD_LIBRARY_PATH="$runtime/usr/lib/x86_64-linux-gnu:$runtime/usr/lib/x86_64-linux-gnu/pulseaudio:$runtime/usr/lib/x86_64-linux-gnu/blas:$runtime/usr/lib/x86_64-linux-gnu/lapack"
exec "$runtime/usr/bin/ffmpeg" "$@"
WRAPPER
chmod 755 "$tools_dir/.ffmpeg-wrapper-$$"
mv -f "$tools_dir/.ffmpeg-wrapper-$$" "$tools_dir/ffmpeg"
echo "Installed project-local FFmpeg. Set FFMPEG_BINARY=$tools_dir/ffmpeg in the Ploi environment."

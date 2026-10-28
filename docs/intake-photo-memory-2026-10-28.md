# Intake photo memory investigation — 2026-10-28

The excessive memory use was reproduced in Chrome using the production
`AttachmentField` component and its real IndexedDB attachment storage. The
browser did not crash. This demonstrates the memory-pressure mechanism; it does
not prove that the affected user's Safari termination had this exact cause.

## Before and after

Eight distinct, deterministic synthetic JPEGs, each 6000 × 4000 pixels (24 MP),
90.97 MiB compressed in total. No customer photographs or staging data were used.
The benchmark mounted eight single-photo fields in a 430 × 850 viewport, updated
one attachment's upload status, scrolled through all eight, then unmounted them.

| Measurement | Before | First fix |
| --- | ---: | ---: |
| Peak renderer memory, repeat run | 806.0 MiB | 200.9 MiB |
| Peak total browser process-tree memory | 1133.8 MiB | 531.1 MiB |
| Renderer memory at end of sampling after unmount | 766.9 MiB | 97.1 MiB |
| Photos loaded on initial opening | 8 originals | 3 nearby previews |
| Displayed source dimensions | 6000 × 4000 | 960 × 640 |
| Extra eager `HTMLImageElement.decode()` calls after updating one attachment | 8 | 0 |

Peak renderer memory decreased **75.1%**; peak browser process-tree memory
fell **53.2%**. An earlier independent run measured 795.6 → 200.8 MiB renderer
peaks, consistent with the repeat. The JS heap stayed around 4 MiB in both:
JavaScript heap alone would miss the image-memory problem.

Memory is Linux proportional set size (PSS), sampled from `/proc/*/smaps_rollup`
every 200 ms for only the dedicated test browser and its children. PSS accounts
for shared pages proportionally. Renderer totals include Chrome's spare renderer;
browser totals also include GPU, network, storage and browser processes. These are
sampled peaks, not hard upper bounds or iOS memory measurements.

Browser: the installed Playwright Chrome/Chromium executable, version
152.0.7977.8, headless Linux. Each run starts a fresh browser process; the same
fixtures and timings are used. Account/auth/intake context is supplied by the
fixture. This is an isolated real-component benchmark, not an authenticated
end-to-end reproduction of the staging page. The baseline bundle was built from
clean commit `96b4c32` before implementation changes.

## Changes

- Web photo previews are generated only when within 300 pixels of the viewport.
- Original loading and thumbnail generation run through one shared queue. Only
  one original is decoded at a time. Preview images have a maximum edge of 960 px.
- Full-size bitmaps, canvas buffers and temporary URLs are explicitly released.
  The older-browser image/canvas fallback is also serialized and cleaned up.
- Previews use the browser image element, avoiding React Native Web's forced
  full-resolution `decode()` path. Originals remain unchanged in IndexedDB and
  are still the files uploaded to the server.
- Upload-status notifications identify the owner and attachment. Status components
  retain only status metadata; immutable photo previews do not reload on status writes.
- The web picker no longer decodes an entire selection just to obtain unused
  dimensions. Picker URLs are released after save, rejection, cancellation or unmount.
- The same bounded preview path handles pending selections and existing server
  attachments. Server requests retain bearer authentication. Failed previews have
  a retry button; timeouts prevent a stalled request from blocking the queue forever.
- Native photo rendering continues using the native image component and local files.

The first fix still downloaded server originals. The follow-up below replaces
that path with private server thumbnails and adds a local preview cache.

## Validation

- 49 focused tests pass, covering photo controls, assignments, offline uploads,
  original persistence, URL release, serialized decoding, cancelled work, error
  recovery, dimensions and older-browser cleanup.
- Web TypeScript check, explicit web-preview TypeScript check and production Expo
  web export pass. `git diff --check` passes.
- Chrome file selection saved all eight originals; SHA-256 checks matched every
  source file. No owned object URLs remained after unmount.
- Cancelling two selected photos released both original URLs and both thumbnail
  URLs, leaving zero live URLs and no saved references.
- Existing server-photo behavior was exercised with synthetic authenticated HTTP
  responses. A simulated 503 did not block other photos; retry recovered it and
  unmount released all preview URLs.
- The broader native Expo TypeScript invocation encounters five existing generated
  route-type errors for `/scanner/processing` in unchanged scanner files. The web
  check and new web files pass independently.

The changes are local and have **not been deployed**. A physical-device Safari
retest remains necessary to confirm the user's original crash is resolved.

## Local artifacts and repeat commands

Artifacts are retained under `output/playwright/intake-memory/` (Git-ignored):
`before.js`, `after.js`, generated JPEG fixtures, raw PSS samples, phase stats,
summary JSON, browser verification results and screenshots. `build.mjs` bundles
the actual component with isolated context; `sample-memory.py` samples only the
dedicated Chrome process tree; `run-memory-benchmark.py` orchestrates the same
sequence through Playwright CLI. Its temporary esbuild dependency is installed
under `/tmp/equi-photo-bench`, outside project dependencies.

With the fixture server running on port 8766, rerun from the repository root:

```sh
python3 -m http.server 8766 --bind 127.0.0.1 --directory output/playwright/intake-memory
# In a second terminal:
python3 output/playwright/intake-memory/run-memory-benchmark.py before before-repeat
python3 output/playwright/intake-memory/run-memory-benchmark.py after after-repeat
```

The frozen `before.js` is intentionally retained so reruns do not accidentally
benchmark the fixed source twice.

## Further reduction: private thumbnails and persistent previews

The follow-up generates private 960-pixel JPEG previews on the server and caches
small previews separately in IndexedDB, keyed by account and immutable attachment
ID. Cache hits bypass the original-image loader and decoder. Originals and upload
bytes remain unchanged. No database migration is needed.

The server checks attachment/horse ownership before accessing or generating a
preview. Thumbnail creation is locked and atomic, retains EXIF orientation,
flattens PNG/WebP transparency on white, and checks a conservative pixel-memory
budget before decoding. Horse/protocol deletion also removes derived previews.
An explicit response header distinguishes thumbnails from an older API that ignores
`preview=1`; the client safely uses its serialized resize path for that response.

Same eight 24 MP inputs and dedicated Chrome process, with separate scenarios:

| Scenario | Previous renderer peak | New renderer peak | Previous whole-browser peak | New whole-browser peak |
| --- | ---: | ---: | ---: | ---: |
| Uploaded photos, empty local cache | 201.6 MiB | 100.4 MiB | 453.5 MiB | 323.6 MiB |
| Local photos, previews cached before reload | 203.8 MiB | 104.9 MiB | 536.2 MiB | 441.0 MiB |
| Local photos, first-ever preview generation | 203.8 MiB | 204.7 MiB | 536.2 MiB | 600.3 MiB |

Uploaded-photo peaks dropped a further **50.2% for renderers** and **28.6% for the
whole browser**. The empty-page renderer baseline was 83 MiB, so the photos now
add about 17 MiB at peak. The eight server previews total 2.43 MiB versus 90.97 MiB
of originals (97.3% fewer image bytes). The actual PHP service generated the
synthetic thumbnails in about 0.23 seconds each; this is a local measurement.

**First-time offline generation is not improved:** it still has to decode one
24 MP original and peaks around 205 MiB in the renderer. Whole-browser totals
for local scenarios also include compressed-blob buffers retained by Chrome after
fixture preparation. The cold run's initial whole-browser PSS was 491.6 MiB,
versus 409.1 MiB for the cached run; these totals must not be read as a universal
page-only or upload-memory guarantee. The uploaded-photo comparison avoids that
original-blob setup and starts both browsers around 293 MiB.

The local test setup now reloads after fixture preparation and before memory
sampling. Remote scenarios use eight PHP-generated image responses through a
synthetic authenticated API, with no originals seeded locally; the upload-status
write is omitted for both remote comparison runs. Browser tests remain isolated
component tests, not an authenticated staging end-to-end run.

Validation for this follow-up:

- 51 focused frontend tests and six PHP tests (133 assertions) pass. Coverage
  includes cache isolation/failure fallback, authorization, unchanged downloads,
  all eight EXIF orientations, PNG/WebP transparency and excessive pixel counts.
- Web TypeScript, explicit preview-file TypeScript and production web export pass.
- The final remote run displays all eight previews with zero bitmap decodes;
  the preceding repeat measured 100.2 MiB renderer / 322.3 MiB whole browser.
- After reload with Chrome offline, all eight local previews display at 960 × 640,
  with zero bitmap decodes. Unmount releases all eight object URLs.
- Staging has GD and EXIF installed and a 512 MiB PHP site override. This was
  checked read-only; no changes were deployed.

Frozen comparison bundles and raw samples remain in the ignored artifact folder:
`after.js`, `thumbnails.js`, `thumbnails-final.js`, `first-fix-remote-*`,
`thumbnails-remote-final-*`, `thumbnails-remote-repeat-*`, `thumbnails-cached-*`, `first-fix-reloaded-*`, and
`thumbnails-cold-final-*`. Reproduce the separate scenarios with:

```sh
python3 output/playwright/intake-memory/run-memory-benchmark.py after previous-remote remote
python3 output/playwright/intake-memory/run-memory-benchmark.py thumbnails-final new-remote remote
python3 output/playwright/intake-memory/run-memory-benchmark.py thumbnails-final new-cached cached
python3 output/playwright/intake-memory/run-memory-benchmark.py thumbnails-final new-cold cold
```

Both backend and web changes are local. Deploy the backend before the web bundle
to enable server previews immediately. Physical-device Safari verification remains
outstanding.

## Upload resolution limit

New intake photo selections and backend photo uploads are limited to a long edge
of 7,680 pixels and a short edge of 4,320 pixels, accepting both landscape and
portrait. Larger photos are rejected with an explanatory message; accepted photos
are not resized or recompressed. The existing 15 MiB upload-size limit remains.
Existing stored photos are not modified by this validation change.

The web picker reads JPEG, PNG and WebP dimensions from small file-header slices,
without decoding the original pixels. Validation runs before durable saving and
before selected photos enter the view-assignment preview screen. The server also
validates dimensions independently of the client.

Real JPEGs at 7,680 × 4,320 and 4,320 × 7,680 both generated previews successfully
under PHP's existing 512 MiB limit, with original SHA-256 hashes unchanged. The
conservative decode estimate for this resolution is approximately 269 MiB; no
server memory-limit increase was needed. Boundary, orientation and header-reader
checks pass: 65 focused frontend tests and eight PHP tests (161 assertions).

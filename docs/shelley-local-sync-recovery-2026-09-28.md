# Shelley local sync recovery — 2026-09-28

Target: Pixel 7 Pro, hardware serial 32281FDH30005Z, account shelleymeeuwsen@gmail.com.

## Confirmed cause

The server had Shelley, Nova, and a published active protocol. Dashboard generation succeeded. The phone displayed no matching horse because its local PowerSync database still contained a different user ID and that user's horse. Its `$local` bucket was `last_op=6, target_op=10`, while the service reported write checkpoint 6 for the current account. The phone repeatedly logged “Could not apply checkpoint due to local data.”

The backed-up database passed SQLite quick_check and had zero queued ps_crud entries. This identifies stale local account/checkpoint state, not a pending unsent edit. The exact historical account-switch sequence was not reconstructed.

## Recovery

Used a temporary debuggable release, with no source changes, to access app-owned storage. Stopped the app and backed up only equinova.db, its WAL, and shared-memory file. Retained originals on-device under the app-private files/powersync-backup-20260928 directory. A second private backup is at /tmp/equi-shelley-reset-20260928/database-backup.

Moved only these three PowerSync files out of databases. Preserved AsyncStorage (RKStorage), login, Expo updates, and media state. Restored the exact original non-debuggable release APK before launching. APK SHA-256: b4bd9abc58e5a7de222d5261c6a61247c456afacc930f6f9867f3134bd29acf6. Version 0.0.8 / code 12.

## Verification

Fresh launch logged “Validated and applied checkpoint” and DidCompleteSync at 23:15:09 local time, and another successful checkpoint at 23:15:23. Home showed Online, Goedenavond Shelley, Nova, and the active protocol at day 19/week 3. Protocol screen was inspected separately after sync. Backend account records were not reset or modified as part of this recovery. No source-level prevention for future account-switch checkpoint reuse was implemented.

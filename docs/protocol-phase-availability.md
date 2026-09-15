# Protocol phase availability and reminders (OPT-39)

## Customer behavior

The phase's scheduled protocol week numbers take precedence over its stored week range. All dates are derived from the protocol start date in the customer's timezone. A phase unlocks at midnight seven calendar days before its start, including across daylight-saving changes. Overlapping active phases are independently accessible. Completed phases remain accessible as history.

Locked phases expose their title and timing only. The dashboard redacts descriptions and supplements from phase details, reminders and the order list. PowerSync now sends phase metadata and scheduling weeks, while phase descriptions and supplements are delivered through this filtered dashboard only. Older unrestricted dashboard cache entries are discarded. Previously fetched content remains usable offline; newly unlocked content needs one successful dashboard fetch. Cached status labels advance at date boundaries and on app resume, and explain when new content needs a connection.

The calendar shows active turquoise cards, soft turquoise previews, and quiet locked cards with a lock icon. Preview sheets include all available supplement instructions and a phase-specific bestellijst. A grouped notification opens the exact horse/protocol/phase identities, with a chooser for the other grouped phases. Stale protocol IDs cannot open a phase from a different publication.

## Deployment requirements

1. Deploy backend code and run migrations, including `2026_09_16_000001_create_protocol_phase_reminders.php`.
2. Deploy/reload `backend/powersync/sync_rules.yaml` so old broad phase streams are removed from clients.
3. Keep Laravel's scheduler running. `protocols:send-phase-reminders` runs every minute with overlap protection. It sends only for the active horse's current published protocol and enabled user/protocol notification preferences.
4. Configure the existing EAS project ID (`extra.eas.projectId` or `EXPO_PUBLIC_EAS_PROJECT_ID`) and Android FCM / iOS APNs credentials. Configure `EXPO_ACCESS_TOKEN` on the backend only if Expo enhanced push security is enabled.
5. Rebuild the native app with `expo-notifications`. The package's Android manifest supplies notification permission and FCM service via normal manifest merging. Provide the platform Firebase native configuration as part of that build. A JavaScript-only update cannot add the native module.

The app registers the current native token and timezone after login and on resume/token change. Registration waits for an EAS project ID before asking device notification permission. Login, logout and token writes share a serialized session queue. Logout waits for a submitted registration before unregistering and invalidates any older pending permission/token work. A new login waits until old credentials and local data are cleared. Logout unregisters on a best-effort basis; a new account claiming the same device clears its old account association.

## Delivery semantics and verification

Phases becoming available on the same date share one Expo push request. A database unique constraint plus a protocol row lock prevents repeat scheduler runs from resending accepted reminders. Each group commits separately. Transport failures leave no sent record and retry while the phase is in preview. Late catch-up uses "Binnenkort" instead of falsely saying a full week remains. Explicit `DeviceNotRegistered` errors remove the invalid token.

`sent_at` records Expo gateway acceptance, not confirmed device receipt. As with any external call followed by a local database commit, a process failure after Expo accepts and before commit can produce a retry duplicate; Expo does not provide an idempotency key for this send API. Client response handling deduplicates the same notification ID. Receipt polling and multi-device-per-user delivery are not implemented; the existing preference model stores one token per account.

Local verification: date/DST/overlap unit tests, authenticated dashboard redaction at the exact boundary, push grouping/retry/opt-out/invalid-token behavior using HTTP fakes, token authentication/transfer/validation, and client payload/cache tests. The mounted sync rules were parsed using the running service's `@powersync/service-sync-rules` parser with zero errors. Local PowerSync was restarted and activated the revised rules as replication stream `powersync_13_a1c3`, replacing stream 12. Native compilation is separate from delivery verification; no real-user notifications were sent in tests.

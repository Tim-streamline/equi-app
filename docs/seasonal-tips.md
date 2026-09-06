# Home seasonal tips

The Home seasonal tip appears immediately after the greeting/horse selector, before protocol and other cards, for Basic and Plus. Its month, title, short introduction and content link come from the existing seasonal-tip management screen and dashboard endpoint. The dashboard now includes a stable tip `id` and an introduction limited to 180 characters. The full managed body remains available in the response. Missing titles can fall back to the linked library title; no title or linked content is invented when both are absent.

The X button is separate from the reading link. It hides that complete card with no minimized replacement. Identity uses the tip UUID, not month, horse, title or modified date: editing the same tip does not undo dismissal, while a new tip record can appear.

Account → Voorkeuren exposes “Seizoenstips op Home” (Aan/Uit), default Aan, with “Toon praktische tips die passen bij het seizoen.” Uit hides both current and future tips. An explicit Uit → Aan transition clears previous dismissals so the current tip appears again. Merely revisiting Home or the preferences page does not clear anything.

## Persistence and deployment

`user_home_preferences` has one row per account; its primary key is the user's ID. `seasonal_tips_enabled` and `dismissed_tip_ids` are stored locally in a PowerSync transaction and uploaded to Laravel, so no network request is needed to hide a card. The row is synchronized only to its own user. The upload policy prevents writing another account's row, and validates booleans and UUID arrays. SQLite JSON strings are decoded before Eloquent's array cast to prevent double encoding. This setting does not change notification/reminder preferences.

Deploy migration `2026_08_31_000001_create_user_home_preferences_table.php`, reload Laravel workers, and reload/restart PowerSync with the new private-table query before deploying the app. No preferences backfill is needed; missing rows mean Aan. An older cached dashboard without tip identity does not render a dismissible card until refreshed.

## Verification — 2026-08-31

- New tests failed before implementation (missing mobile behavior and dashboard tip identity), then passed.
- SQLite-backed mobile tests verify dismissal after database close/reopen, account isolation, multiple dismissed tips, new tip visibility, disabled persistence and re-enabling.
- Laravel tests verify stable tip identity, managed presentation/link data, valid sync roundtrips, no JSON double encoding, invalid-payload rejection and cross-account write protection.
- Full backend suite: 117 tests / 860 assertions passed. All 12 mobile test files and TypeScript pass; lint has no errors and seven existing unrelated warnings. PHP formatting passes.
- Android release APK built and installed in place on Pixel 7 (`panther`, `28061FDH200HZQ`). The device retained the existing account/session; physical checks are detailed below.

The existing local August tip has no title or library link in the CMS. Its managed content remains unchanged. Temporary local dated tips are used for device QA and removed afterwards.


## Pixel 7 outcome

The installed release APK has SHA-256 `4f269f9a5fdbae7cad620beeb85cae0c09a4318085a8f6fe1e44eec9ad99afcf`. Laravel and PowerSync health checks returned HTTP 200. A dismissal of temporary tip A was persisted in the real account row, and Home showed no seasonal card or minimized replacement afterwards. The app remained Online and focused logcat contained no fatal, React Native JavaScript or SQLite errors.

The device then repeatedly reached its pattern lock (one-minute screen timeout). The full Account → Voorkeuren, re-enable, new-publication and link interaction sequence could not be completed physically; those preference/persistence scenarios are covered by the automated tests, not claimed as physical UI proof. No lock setting was changed.

Both temporary tips and only their dismissal IDs were removed. The original CMS content remains unchanged. The account's global setting remains Aan; no test content is left in the local database.

# Protocol/Home change verification

Sources: seven supplied PDF documents, read and first-page visual references inspected.

- Backend dashboard: per-owner authorization; timezone-aware protocol timing; progress; calendar states; upcoming phase notices; configurable weekly reminders and saved updates.
- Nutrition: explicit target weight, 2–3 kg/100 kg range; adjustable sugar/protein; latest submitted intake; replaceable Metazoa/Okapi policy plus therapist overrides; source-water warnings; direct configurable library links.
- Calendar: individual phase buttons, dynamically styled status and scrollable detail sheet with fixed close action.
- Zorg: selected management and movement advice in four sections; action, instruction, personal note, frequency and link controls remain in Protocolbeheer.
- Analysis: therapist-authored compact summary, 3–4 substantive focus points and short evaluation observations; no internal scores, badges or daily tasks. See the compact Analysis update below.
- Home: Basic/Plus variants, server greeting, account credit balance/unlocks, date-rotating relevant recommendations, seasonal content, shared horse selection. The Plus CTA opens a dedicated page with managed plan content and a server-formatted price.
- Shelby: existing assistant screen now scoped to the selected user's horse session; no new AI provider introduced.

## Backend contract and deployment

`GET /api/horses/{horse}/dashboard` accepts an IANA `timezone` and optional `month=YYYY-MM`. It requires the existing PowerSync JWT and an active horse owned by the authenticated user. Draft and future publications are excluded. Protocol timing, phase state, calendar completion, progress, nutrition quantities, reminders, relevance ranking and recommendation rotation are calculated by Laravel.

`POST /api/horses/{horse}/weekly-update` saves the due update for the selected protocol and week. The therapist can read submitted updates in Protocolbeheer.

Run both new `2026_08_28` migrations before deploying the app. They add protocol presentation settings, credit balances/unlocks, library prices, weekly updates, and a seasonal-tip title. Migrations were applied to the local backend; production deployment was not performed.

The app refreshes on focus, reconnection and every 30 seconds while focused. Its offline snapshot is keyed by user, horse and calendar month. The checklist retains PowerSync writes; totals are refreshed from the backend.

## Automated checks

Four initial feature tests failed with HTTP 404 before implementation. A further Plus-offer regression failed before its fix. Final backend suite: **91 tests, 684 assertions**, passing. Mobile: **9 test files**, TypeScript, and Android release builds passed. Lint reports zero errors and seven existing warnings in unrelated UI components. Backend admin assets also built successfully.

## Pixel 2 verification

Tested over Wi-Fi on Pixel 2 / Android 11. The normal app was updated in place. A separate QA package and temporary account with two horses exercised populated and empty protocol states without clearing the original user's session or drafts.

- Switched between Plus and Basic horses; checked credits, unlocked content, relevant recommendations, managed seasonal content and the Plus information page.
- Opened Today from the Home protocol card; checked a supplement and verified the persisted backend intake and recalculated total.
- Submitted a weekly update; confirmed persistence and removal of its reminder.
- Opened the upcoming-phase order list and calendar phase details; scrolled long lists while the close action remained accessible.
- Checked 450 kg target weight producing 9–13.5 kg roughage. Changed only the QA target to 500 kg and verified 10–15 kg after refresh.
- Checked default feed rules, a live manual override and its note, groundwater warning, and direct hay/water library article links.
- Inspected grouped management advice and analysis priorities.
- Opened Shelby in both variants; confirmed distinct backend chat sessions for the two horses and the selected-horse prompt.
- Focused QA-process logcat showed no app errors, SQLite errors or PowerSync errors; platform resource/RNScreens warnings remain.

The temporary QA package, account, fixtures and credentials were removed after testing. The final normal package was then installed in place and opened with the original Shelley/Nova session intact; Home → Today was rechecked.

## Existing integrations and content gaps

Nova has no target weight or submitted intake nutrition values; the UI reports missing data rather than inventing values. Existing local seasonal content still includes seeded placeholder text and needs editorial completion. Therapists can set target weight, nutrition thresholds, manual feed decisions, library links, management presentation and reminder timing in Protocolbeheer.

New credit tables start with zero balances and require real account balance/unlock data from the eventual credit integration. No billing, content purchase enforcement or credit-purchase integration was present or added. The Plus page is informational. The existing assistant still uses stored fallback replies; no AI provider was introduced.


## Zorg tab — 2026-08-31

- Mobile navigation is now Vandaag / Kalender / Voeding / Zorg / Analyse. Five equal-width buttons replace the horizontal scroller. Existing `tab=management` and `tab=beweging` links resolve to Zorg.
- Zorg uses the existing dashboard `management` and `movement` fields, including cached responses. Categories come from Protocolbeheer; mobile does not infer them from advice titles. No API or database migration is needed.
- Sections are Leefomgeving & weide, Beweging & belasting, Lichamelijke zorg and Onderzoek, in that order. Empty sections are omitted; an empty protocol selection gets one neutral empty-state message.
- Section headings are uppercase with small turquoise icons directly on the canvas. Every advice has its own white rounded card, with 14 px between cards and 32 px between sections. Titles and full managed descriptions remain intact, including movement duration, frequency and restrictions. Personal notes, frequencies and links are retained. Avoid/stop advice gets a static warning icon, not a task checkbox or status badge.
- Analysis and daily supplement actions remain in their separate tabs. Only the selected horse's current active, published protocol supplies care advice; catalog-only, deselected, paused, draft, future-publication and other-horse advice are excluded.

Verification: the new mobile regression initially failed before implementation, then all four care/navigation cases passed. All 11 mobile test files, TypeScript and Android/Hermes bundle export pass. Full backend suite passes: 113 tests, 825 assertions, against an isolated temporary PostgreSQL database. Lint has no errors and seven existing unrelated warnings.

The actual React Native components were rendered through Metro/React Native Web with temporary test fixtures at 320, 360, 390 and 412 px. All five labels fit without horizontal overflow. Measured card/section spacing is 14/32 px and section backgrounds are transparent. All tabs were clicked three times; populated, empty and movement-only states passed. No checkbox controls or browser errors were present. This is browser component verification, not physical Android validation; no device install was performed.

Admin assets compile successfully. The build's Octane reload step cannot complete while the existing local Laravel service is stopped; it was left stopped. Two existing assertions in `protocolTerminology.test.js` fail because HEAD already contains the separate “Protocol Settings” label. These unrelated naming assertions were not changed. The temporary preview files, browser session and test database were removed after verification.


### Pixel 7 installation — 2026-08-31

The release APK containing Zorg was installed with `adb install -r` on the confirmed Pixel 7 (`panther`, serial `28061FDH200HZQ`). Existing app data was retained. Package `com.anonymous.expoapp` remains version `0.0.8` / code `12`; Android reports update time `2026-08-31 18:06:49`. APK SHA-256: `22af1dd914df30cceced5ad9484075c65ef06d24ee0f31277bb5641896f837e9`.

The build completed successfully and installation returned `Success`. Android accepted the MainActivity launch (`Status: ok`) and the app process was present. Local Laravel and PowerSync health endpoints both returned HTTP 200. After the user unlocked the device, the app opened with the existing Shelley/Nova session and Online status. All five protocol tabs fit on the physical screen. Zorg displayed Nova's two environment and two movement advice cards, with separate uppercase section headings directly on the off-white canvas. The other two sections are correctly omitted because this protocol has no advice selected for them. No checkboxes or internal status labels were visible in Zorg. Initial process logs showed no fatal, JavaScript or SQLite errors; existing Bridgeless compatibility warnings remain.


All five tabs were subsequently opened three times on the Pixel 7 (15 successful selected-state checks). Each return to Zorg showed the selected environment/movement sections and no daily completion controls. The app was left on Zorg. Focused process-log checks found no fatal, React Native JavaScript or SQLite errors; the UI showed Online, but no explicit `DidCompleteSync` event was available in the captured release log.

## Compact Analyse — 2026-08-31

The Analyse tab now answers what stands out, what the protocol targets, and what to observe during evaluation. It contains one light turquoise personal-summary card, 3–4 compact white focus cards and an unboxed list titled “Waar letten we op?”. Focus cards have a short title and one goal sentence. There are no daily task controls, backend badges or generic advice categories.

Protocolbeheer → Analyse has dedicated per-protocol fields: `protocol_analyses.summary`, `focus_points` (JSON title/body pairs), and `observations` (JSON strings). The summary allows up to four sentences / 600 characters; focus titles up to 48 characters; goal and observation sentences up to 160 characters. Publishing a populated compact analysis requires a summary, 3–4 focus points and 1–4 observations. Drafts may be incomplete. Editorial guidance explicitly asks for tentative relationships, not unproven causal claims. Generic category titles are rejected; substantive topics are freely authored, not hardcoded.

Existing `cause` and `protocol_advice` data remain intact as expandable read-only reference in Protocolbeheer. They are deliberately not automatically rewritten or reclassified into personal medical conclusions. No migration populates example content. Existing protocols therefore need their new compact fields authored before Analyse shows content; until then the app says the personal analysis has not yet been filled in. Publishing protocols without compact analysis remains possible for compatibility.

The existing horse dashboard returns the authored summary, up to four focus points and the observations for the selected horse's current active publication. Cached responses from the older API (without observations) are treated as an unfilled compact analysis, preventing the old long document from reappearing offline. Original text is never silently truncated. PowerSync's existing analysis selection already selects all columns; its mobile schema includes the three new fields.

The additive migration `2026_08_31_000002_add_compact_protocol_analysis.php` was applied locally. Admin assets built and Octane was reloaded. Production deployment was not performed. Five initial regression tests failed before implementation. Final backend suite: **121 tests, 909 assertions**, passing, including create, publish, edit, preserve omitted fields, explicit clear, validation and legacy preservation. All **12 mobile test files**, TypeScript and PHP style checks pass; lint has 0 errors and the same 7 unrelated warnings.

The actual React Native components were checked through a temporary Metro browser route at 320, 360, 390 and 412 px. All five tabs fit without horizontal overflow; populated and empty states were checked. Browser preview code was removed before the Android build.

Release build: `BUILD SUCCESSFUL in 17s`. APK SHA-256: `6e550ac3a6622fbdce727db8f5335c04041f5f26dba934b56e1dcfb2dcf6f04f`. Installed in place on Pixel 7 (`panther`, serial `28061FDH200HZQ`), retaining the Shelley/Nova session and version `0.0.8` / code `12`. `adb install -r` returned Success; MainActivity launch returned Status: ok. Laravel and PowerSync health endpoints returned HTTP 200.

The device was initially pattern-locked, then unlocked. Temporary non-medical analysis content was saved to the selected test protocol with a backup of its original new fields and timestamp. The populated view showed all three sections and three focus cards; switching Zorg → Analyse three times and restarting the app passed, with no task controls. No screen-lock settings were changed.

A fourth focus point was then added only in the local backend, and appeared on the Pixel after refresh without an app rebuild; the observation section remained visible. Afterwards the original `summary`, `focus_points`, `observations` and `updated_at` values were restored. The app was refreshed and verified to show the neutral unfilled-analysis message with no temporary focus points. Temporary database helper/backup files and browser preview code were removed. Final focused logcat: 0 fatal errors, 0 React Native JavaScript errors and 0 SQLite errors. The app was left on Analyse with the original Shelley/Nova account and content preserved.

# Protocol/Home change verification

Sources: seven supplied PDF documents, read and first-page visual references inspected.

- Backend dashboard: per-owner authorization; timezone-aware protocol timing; progress; calendar states; upcoming phase notices; configurable weekly reminders and saved updates.
- Nutrition: explicit target weight, 2–3 kg/100 kg range; adjustable sugar/protein; latest submitted intake; replaceable Metazoa/Okapi policy plus therapist overrides; source-water warnings; direct configurable library links.
- Calendar: individual phase buttons, dynamically styled status and scrollable detail sheet with fixed close action.
- Management: selected advice only, three categories; action, instruction, personal note, frequency and link controls in Protocolbeheer.
- Analysis: therapist summary and up to five priorities; no internal scores or badges.
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

# Open Linear issues: investigation and fix plans

Investigated 23 September 2026 against checkout `efd610f` and its existing uncommitted changes. This is a plan, not an implementation or deployment report.

## Scope and evidence

The workspace-wide, non-archived Linear listing returned all results in one page. Seven issues are open, all in **Backlog**: OPT-30 and OPT-46–OPT-51. OPT-30 is Low priority; the other six have no assigned priority. There are no open Todo or In Progress issues in this snapshot. Six issues have no project assignment, so filtering only by the Equi App project would omit them. Full descriptions, comments and relations are saved in [the issue snapshot](linear-open-issues-2026-09-23.json). All seven have no comments or declared issue dependencies.

Investigation covered the actual Home and Library screens, dashboard API, access/unlock/bookmark APIs, PowerSync tables and rules, protocol editor settings, seeders, deletion services, foreign keys, web route sharing and existing tests. Existing uncommitted implementations are prerequisites of these plans and must be preserved.

Confirmed baseline: `node --test expo-app/tests/library-filter.test.mjs expo-app/tests/library-bookmark.test.mjs expo-app/tests/horse-dashboard.test.mjs` passed **14 tests**. These establish existing behavior; they do not prove the requested features work.

Docker is running, verified outside the failing sandbox. The EquiApp `backend-laravel.test-1` and `backend-pgsql-1` containers are stopped. No backend tests, live database inventory, authenticated browser flow or device reproduction was performed. Exact hay-content identities and actual test records remain unverified. No application code, Linear issue, or database record was changed during this investigation.

## OPT-46 — Compact Home, floating Shelby, four library items

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-46)

**Confirmed problem.** [HorseDashboard.php](../backend/app/Support/HorseDashboard.php) limits ranked recommendations to two with `take(2)`. [home.tsx](<../expo-app/app/(tabs)/(pager)/home.tsx>) renders them in one non-wrapping row and includes the large Shelby card inside the scroll content. A seasonal tip currently precedes the protocol card. The Home cards are separate from the shared Library cards.

**Plan.**

1. Return up to four distinct published recommendations, preserving the existing relevance ranking and daily rotation. Return fewer when the catalog has fewer than four; do not invent fillers.
2. Add a compact presentation to the existing shared Library card, keeping the full description layout for the Library overview. Render Home recommendations as a 2 × 2 grid with thumbnail, bounded title, format/duration and concise access metadata.
3. Add `isPlus` to dashboard item metadata and adapt the existing `hasPlus`/`unlocked` values so Home uses the same access-label rules as Library. Zero credits alone must not label a Plus-only item as free.
4. Replace the large Shelby card with a fixed bottom-right button outside the ScrollView. Preserve `/nova-chat` navigation and the selected horse/protocol parameters. Account for tab-bar height, safe-area insets and extra scroll space so content remains reachable. Keep it available for both Basic and Plus.
5. Put the active protocol block first in the Plus content area; retain the seasonal tip and existing Basic content/Plus CTA. Preserve current dashboard eligibility semantics rather than redesign subscriptions as part of a layout change.

**Verification.** API tests for four results, fewer available items, unpublished exclusion, stable ranking and both dashboard variants. Screen checks on narrow/wide phones and web for 2 × 2 wrapping, readable metadata, large text, scrolling, tab-bar overlap and correct chat context after switching horse. Work together with OPT-48.

## OPT-48 — Content type on Home library cards

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-48)

**Confirmed problem.** Home passes `format` to `LibraryThumbnail` but does not render the overlay used by [LibraryCard.tsx](../expo-app/components/library/LibraryCard.tsx). That shared card already selects icons from the real format and uses `libraryFormat()` for labels. The dashboard already returns format and duration.

**Plan.**

1. Reuse the shared format badge in the compact Home card from OPT-46: bottom-right overlay, video/play, article/book and audio/headphones. Continue supporting podcast, course and program formats accepted by the CMS.
2. Keep badges visible independently of Basic/Plus membership and access status. Derive the label from each item's format rather than assigning one label to all Home cards.
3. Consolidate navigation while sharing cards: [horse-dashboard.ts](../expo-app/lib/horse-dashboard.ts) currently routes every non-article item to the video screen, whereas [library.ts](../expo-app/lib/library.ts) routes only video there. Use the existing Library routing contract and verify audio/podcast behavior; do not add a new media player under this issue.

**Verification.** Render article, video and audio examples for Basic and Plus, with and without thumbnails/duration. Verify badge placement, truncation and destination using the actual Home card. Extend route coverage beyond the current article/video cases.

## OPT-47 — Saved-items filter

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-47)

**Confirmed problem.** [library.tsx](<../expo-app/app/(tabs)/(pager)/library.tsx>) has category chips and search but no saved-items mode. `library_bookmarks` is already user-scoped in [PowerSync rules](../backend/powersync/sync_rules.yaml) and the client schema. The API already supports idempotent bookmark PUT/DELETE. [LibraryBookmarkButton.tsx](../expo-app/components/library/LibraryBookmarkButton.tsx) updates only its own hook instance after a request; [useLibraryResource.ts](../expo-app/hooks/useLibraryResource.ts) has no shared cache or mutation notification.

**Plan.**

1. Introduce an independent `all | saved` mode above the catalog. Keep category selections separate: the existing “Alles” currently clears categories and should not accidentally reset the saved mode or become a duplicate ambiguous control.
2. Add a current-user bookmark query over the existing synced table. Combine saved IDs with search, category and the filters from OPT-49.
3. Share bookmark mutation state between detail buttons and the list, keyed by user and item. Apply an immediate update and roll it back on failure; reconcile successful changes with API readback/PowerSync without allowing a stale synced snapshot to resurrect a removed bookmark. Clear state on logout/account changes. Keep the existing authenticated server mutation as the persistence path.
4. Add a save/remove affordance to cards in the saved view, with event handling that does not also open the item. Removing a bookmark must immediately remove that card from the saved list.
5. Use the specified empty copy: “Nog niets opgeslagen” and “Bewaar interessante artikelen, video's en andere content zodat je ze hier makkelijk terugvindt.” A “Bekijk de bibliotheek” action switches to all mode. Distinguish an empty saved collection from no matches under other active filters.

**Decision confirmed.** Alles includes every published catalog item, including visibly locked items. “Al ontgrendeld” restricts results to content the user can already open.

**Verification.** Exercise real bookmark/list interactions: save from detail and return, remove directly from saved results, failed save/remove rollback, delayed sync, reopening, account switching, empty state and combined filters. Retain API privacy/idempotency coverage in `LibraryDiscoveryTest.php`.

## OPT-49 — Credit and already-accessible filters

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-49)

**Confirmed problem.** [library-filter.ts](../expo-app/lib/library-filter.ts) only implements text plus category matching. There is no advanced-filter UI or content-type filter in the current app, despite the issue referring to existing content-type filters. Catalog rows already contain format, credit cost and Plus status; `/api/library/access` supplies Plus entitlement and purchased unlock IDs.

**Plan.**

1. Add a compact Filters panel with content type and multi-select credit choices: Gratis, 1 credit, 2 credits, 3 credits, 4+ credits. Integrate existing categories and OPT-47 saved mode.
2. Define deterministic composition: OR within selected categories, formats or price bands; AND between those groups, saved mode, search and the accessible-only toggle. No selected choices means unrestricted for that group.
3. Treat price bands as the item's configured credit price, independent of a user's current balance or subscription. Gratis means `!isPlus && creditCost === 0`. Exclude Plus-only items from credit-purchasable price bands so zero-cost Plus-only items are not misrepresented as free. Confirm this behavior during review if product expectations differ.
4. “Al ontgrendeld” follows the issue's definition of content the user can already open: active Plus OR an existing unlock OR free non-Plus content. Match [LibraryDiscovery::canRead](../backend/app/Support/LibraryDiscovery.php), not just membership in `unlockedIds`.
5. Show removable active-filter chips and “Wis filters”. Proposed behavior: clear category/format/credit/access/saved filters while leaving the independent search text, which has its own X in OPT-50. Update results immediately on each change.
6. Reconcile access state after unlocking, membership refresh, screen focus and account change. On unavailable access data, show loading/retry for an access-dependent filter instead of presenting an error as an empty catalog. Filtering never replaces server-side content checks.

**Verification.** Test costs 0, 1, 2, 3, 4 and higher; Plus-only zero-cost content; multiple bands; free, purchased, active Plus and expired Plus access; search/category/format/saved combinations; chip removal and reset. Exercise the actual filter screen and an unlock-to-list transition in addition to pure predicate tests.

## OPT-50 — Clear button in Library search

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-50)

**Confirmed problem.** The search TextInput is controlled by `searchQuery`; results already recompute on changes. No clear button is rendered beside it.

**Plan.**

1. Add a subtle gray X when the raw search string is nonempty, including whitespace-only input. Clear `searchQuery` on press so results reset immediately under the remaining filters.
2. Give the button an accessible label and at least a 44 × 44 touch area even with a smaller icon. Preserve/refocus the TextInput and configure keyboard tap handling so tapping X works on the first press while the keyboard is open.
3. Keep categories, saved mode and price filters intact when clearing search. Leave the existing route-driven `q`/`t` behavior functional for other links.

**Verification.** Type, clear, retype; check keyboard focus, immediate results, X visibility and screen-reader label. Repeat with active filters and a route-supplied query. A focused interaction check is sufficient; no backend changes are needed.

## OPT-51 — Hay CTA opens exactly two existing items

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-51)

**Confirmed problem.** [protocol.tsx](<../expo-app/app/(tabs)/(pager)/protocol.tsx>) uses `LibraryLink`: it opens one item if `hayLibraryItem` resolves, otherwise the general Library with a “Hooianalyse” query. [HorseDashboard.php](../backend/app/Support/HorseDashboard.php) selects a configured single item or the first title/description match. There is no exact two-item collection. The protocol CMS already exposes a per-protocol `hay_library_item_id` selector.

**Required selection, in this order:**

1. Ruwvoer laten analyseren: welke analyse kies je?
2. Het suikergehalte van hooi zelf meten met een refractometer

**Plan.**

1. Query the intended environment for these existing records and verify their identity, format and publication status. Titles were not found in source seeders; this does not establish whether they exist in the database. Exact IDs/slugs remain unverified because the local database is stopped.
2. Store an ordered server-side selection under a stable key such as `hay-analysis`. Resolve verified existing IDs per environment, or stable existing slugs after checking uniqueness. Do not perform fuzzy matching at every tap or hardcode article/video content. Keep selection configuration deployable across environments.
3. Add an authenticated selection response containing only the two published summaries plus existing access metadata, and a dedicated shared Expo selection route that renders normal Library cards. Reuse existing detail routes and access/unlock checks. If content is missing/unpublished, show an explicit unavailable state and flag the configuration; never fill the selection with unrelated items. Verify both items before release.
4. Change only this CTA's onPress destination. Keep its text, description, thumbnail/layout and styling exactly as rendered today, including its existing display metadata. The reusable water CTA retains its own behavior.
5. Resolve the old CMS setting consistently with the user's decision. If this is a universal pair, retire or clearly repurpose the hay destination setting so editors are not offered a destination override that no longer works. Preserve existing metadata needed to keep the CTA's appearance unchanged.

**Decision confirmed.** Always show the specified pair for every protocol. The old per-protocol selection now controls CTA imagery only and is labelled accordingly.

**Verification.** API response contains exactly the configured two items in order and no unrelated/draft items. Tap the unchanged CTA, verify two cards with thumbnail/title/type/duration, open each correct item, navigate back, and exercise free/credit/Plus access. Check missing configuration and the unaffected water link. Generate web route wrappers when adding the new shared route.

## OPT-30 — Remove dummy/test data across backend and app

[Linear issue](https://linear.app/0ptimize-it/issue/OPT-30)

**Confirmed source of risk.** [DatabaseSeeder.php](../backend/database/seeders/DatabaseSeeder.php) mixes production reference/configuration seeders with generated users, horses, content, community, bookings, chat and engagement data. `LibrarySeeder` generates roughly fifty items and fabricated seasonal tips; `UserSeeder` creates an anchor account and 24 random users. Some seeded identities are real people and real content may have been edited from seeded rows. No common `is_demo`/`is_test` provenance marker or cleanup command was found in application/migration searches. Seed origin or an email/title pattern alone cannot safely establish that a current record is disposable.

**Relationship findings.** Horse deletion cascades protocols/observations but deliberately detaches scans, bookings, intake responses, chats and exports in [HorseDeletion.php](../backend/app/Support/HorseDeletion.php). Deleting a user can leave community content with a null author. Therapist deletion is restricted by bookings, and the CMS delete action archives therapists. Moderation subjects use non-FK IDs; media references and JSON arrays such as related-content pins also need explicit review. Deleting only parent records will not satisfy this issue.

**Plan.**

1. Confirm target environment(s) and protected accounts/content with the user. Inventory every module in that database, including existing soft-deleted/archived rows, media, financial/subscription rows, scans, seasonal tips, moderation and indirect references.
2. Produce a dry-run manifest of exact candidate IDs, evidence of test origin, related rows, expected counts and records that must be retained. Separate uncertain candidates for review. Preserve real users/admin access, production library content, categories, plans, settings, questionnaires, products and protocol/advice templates unless individually confirmed disposable.
3. Build an idempotent cleanup operation over that manifest. Preview the complete dependency impact, including real records attached to test parents. Reassign/detach where justified rather than cascading through protected records. Take a restorable backup and review the concrete manifest before any destructive run.
4. Delete approved relational rows transactionally in a valid order. Handle detached records, moderation references, JSON content links, and unreferenced media explicitly. Defer filesystem deletion until the database operation succeeds and reference checks prove files are unused. Preserve an audit of the operation.
5. Split production/bootstrap seeders from opt-in demo seeders so a later deployment or seed command cannot repopulate fake data. Add provenance to future demo data. Audit app fallback IDs/cached dashboard responses so deleted demo content does not reappear in screens or offline caches; preserve genuine static interface copy.
6. Verify before/after counts, no dangling references, protected-record checksums/identities and a second run with zero changes. Check every relevant admin list, selector and dashboard, then PowerSync convergence and app/web views for retained users.

**Deferred by the user.** Skip OPT-30. Do not perform the proposed cleanup.

**Verification.** Test against a disposable restored copy containing both real and demo records with shared relationships. Prove rollback, preservation of real content, removal of explicitly selected detached test data, and idempotence. Do not use blanket truncate, `migrate:fresh`, author deletion, or name-pattern deletion as the cleanup strategy.

## Delivery order and shared validation

1. OPT-50 can be implemented independently as a small search interaction change.
2. OPT-48 and OPT-46 share compact card work; deliver the format/access presentation with the four-item dashboard and floating chat layout.
3. OPT-47 establishes saved state; OPT-49 composes saved/search/category/format/price/access filters. Agree on Alles semantics before finalizing this group.
4. OPT-51 reuses the card and access work but depends on verified content identities and the protocol-setting decision.
5. OPT-30 is a separate data operation dependent on the target environment and reviewed record inventory. It need not block the UI work, but its retention manifest must protect the two OPT-51 items.

All shared screens also serve the separate `/web` app through generated route wrappers; edit their Expo source and include web in validation. Read the exact Expo SDK 54 documentation required by `expo-app/AGENTS.md` before implementation. Start the intended local Laravel/PostgreSQL services when runtime validation is needed and run backend tests through `backend/vendor/bin/sail` against the testing database. Add focused regression tests for changed behavior, then typecheck and inspect the affected user flows on web and a mobile device. Passing source-pattern/unit tests alone is not evidence of keyboard, layout, sync or navigation behavior.

The user approved implementation of OPT-46–OPT-51 with the decisions above and deferred OPT-30. See `linear-implementation-2026-09-23.md` for implementation and verification results.

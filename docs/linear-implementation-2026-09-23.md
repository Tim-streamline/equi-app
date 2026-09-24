# Linear implementation: OPT-46–OPT-51

Implemented on the existing checkout, preserving prior uncommitted work. OPT-30 is deferred at the user's request. No staging/production deployment or native-device installation was performed.

## Delivered

- **OPT-46:** Home receives up to four published recommendations with existing ranking and daily rotation. Shared compact cards render in a 2 × 2 grid, with capped thumbnail height on larger screens. The protocol precedes seasonal content. Basic retains the Plus CTA. The fixed Shelby button preserves horse/protocol context and stays above bottom navigation.
- **OPT-48:** Home and Library use the same actual-format icon/label overlay, duration and access labels. Home receives `isPlus`, preventing Plus-only content with zero credit cost from appearing free. Non-video routes now match Library's existing routing.
- **OPT-47:** Independent Alles/Opgeslagen modes; every published item remains discoverable in Alles, including locked items. A shared account/session-scoped bookmark store immediately updates detail and saved-list views, rolls back failed mutations and rejects stale reads/session mutations. Saved cards support removal; the specified empty state and return-to-library action are present.
- **OPT-49:** Multi-select Gratis/1/2/3/4+ credit bands, actual content-type filtering, existing categories/search, saved mode and Al ontgrendeld compose together. Active chips are removable; Wis filters clears filters while keeping the separate search query. Accessible means free, purchased or active Plus. Plus-only items are not presented as credit-purchasable/free. Access-dependent filters show loading/errors rather than a misleading empty list.
- **OPT-50:** Search X appears for any nonempty input, has a 44 × 44 target and an accessibility label, clears immediately and keeps/refocuses the input without clearing other filters.
- **OPT-51:** The visually unchanged Nutrition CTA opens a dedicated selection page for the same two existing items for every protocol. Both navigate through the normal Library detail/access flow. The old protocol hay-item selector is labelled as controlling imagery only.

## API and content

- `GET /api/library/bookmarks` returns the authenticated user's existing bookmark IDs. This authoritative list and the shared mutation store avoid waiting for PowerSync to update the saved view; focus refresh picks up server changes. No second bookmark table or write path was added. Bookmark loading/mutation requires the backend, like the existing detail/save flow.
- `GET /api/library/selections/hay-analysis` returns exactly the configured published pair, in order, plus access metadata. Missing/unpublished content returns a useful unavailable response rather than unrelated or partial results. No article/video body is hardcoded or exposed by this summary endpoint.
- `backend/config/library.php` uses the two existing slugs verified in the local database. Slugs are retained by library export/import; IDs may differ between environments. Verify both configured slugs exist and are published in any deployment target before releasing the client.
- No new database migration is required for this batch. Existing uncommitted Library API/schema work remains a prerequisite. Deploy the compatible backend before the updated app/web frontend.

## Verification

- Full Laravel suite: **205 tests, 2,854 assertions passed**.
- Mobile suite: **74 tests passed**, including actual screen-control composition, bookmark button/hook integration, optimistic rollback, stale-read protection, concurrent mutations, account changes and non-video destinations.
- Admin frontend suite: **32 tests passed**.
- Web suite: **6 tests passed**.
- Expo and web TypeScript checks passed; focused Expo ESLint and touched-PHP Pint passed.
- Admin and web production builds passed. Existing build/package warnings do not fail these checks.
- Authenticated Chromium checks at 390 × 844 and 1440 × 1000: four Home cards, format badges, fixed Shelby placement/context, Basic locked labels, search/clear behavior, combined credit filters, Basic accessible-only filtering, save from detail, immediate removal from saved results, exact two-item Nutrition selection, and both article/video destinations. No authenticated browser console errors occurred in the tested flows.
- The pre-existing Basic account had only an archived horse. A uniquely identified temporary local QA horse was used for Basic Home; that horse and the empty chat session created by opening Shelby were removed after verification. The test bookmark was restored to its original absent state. Existing content/accounts/archived horses were retained.

Screenshots: `output/playwright/linear-home-mobile.png`, `linear-home-basic.png`, `linear-home-desktop.png`, `linear-library-filters.png`, and `linear-hay-selection.png`.

Browser checks establish shared web behavior. Native keyboard/safe-area interactions were not exercised on a physical device, and there is no claim of a deployed or installed mobile release.

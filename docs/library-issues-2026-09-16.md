# Library and app issues — 16 September 2026

## Scope

- OPT-27: verified the existing booking deletion action, confirmation copy, cancellation, authorization, rollback and audit coverage. No booking was deleted during browser verification.
- OPT-35: dynamic CMS category chips, multi-select OR filtering, synchronized dropdown, searchable overflow menu, counts, pinned categories and manual order.
- OPT-36: removed the membership notice at the top of the Community feed. Existing participation rules still admit Basic and Plus members.
- OPT-41: wired article/video bookmark buttons to authenticated, idempotent save/remove endpoints with current state, pending state and failure feedback. Existing user-scoped PowerSync bookmark replication remains available.
- OPT-42: up to four published related items, ordered manual pins, category-overlap ranking and title/description relevance as the tie-breaker. The editor supports title search, selection, removal and ordering. Library export/import remaps suggestion IDs by destination identity.
- OPT-43: shared two-column cards with a two-line title, three-line CMS description, format icon and duration on the thumbnail, and access metadata.
- OPT-44: removed supplement frequency labels from the app detail cards without changing dosage or scheduling data.
- OPT-45: new CMS items default to Plus only unchecked; editing preserves the saved value.

## Access flow and rollout

Library detail screens now request content through the authenticated Library API. Free items, active Plus subscriptions and existing credit unlocks permit viewing. Other items display the Plus or credit prompt. Credit unlocks require an explicit confirmation, use the current quoted cost, serialize account spends and do not charge twice for repeated requests.

Deploy the backend and run `php artisan migrate` before releasing the updated mobile app. The additive migration stores category pin flags and ordered suggestion IDs. It has been applied to the local development database only.

The updated detail, bookmark and recommendation flows require a backend connection. The catalog remains synced locally. This change does not redesign the existing PowerSync catalog replication or public media storage into a secure content-delivery system; those are separate from the detail-screen access checks.

## Verification

- 54 affected Laravel tests / 482 assertions passed (Library, Community and booking deletion).
- All 19 mobile test files and 11 admin frontend test files passed.
- TypeScript, targeted ESLint, Pint, production frontend build and `git diff --check` passed.
- Authenticated local browser checks: quick category selection, dropdown synchronization, multi-selection, overflow search, pin search/add/reorder/remove, category pin/order controls, Plus-only default, and booking confirmation/cancellation.
- Existing real embedded video rendering is covered in both article and video detail tests; locked content does not render a native player.
- No mobile-device installation, physical-device interaction, staging deployment or production deployment was performed.

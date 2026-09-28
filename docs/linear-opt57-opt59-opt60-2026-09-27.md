# OPT-57, OPT-59 and OPT-60 implementation — 27 September 2026

## Scope and decisions

- Live payments are deferred by request. The existing unconfigured payment gateway remains in place; no live Basic renewal or credit-bundle checkout is claimed.
- Previously purchased library access remains permanent when an item becomes Plus-only. Everyone else needs active Plus for that item; it cannot be unlocked with credits.
- Scheduled credit-expiry notifications through Expo are explicitly authorized.
- This document supersedes the Plus-access and pending-push statements in the 26 September implementation notes.

## Implemented

### OPT-57: remaining credit features

- Purchased-credit reminders at 30 and 7 days, respecting notification opt-in, disabled accounts, remaining balances and device tokens. The scheduler runs every 15 minutes, records accepted tickets for deduplication, retries failed sends and checks delivery receipts. Invalid device tokens are cleared without removing a newer replacement token.
- Notification taps open the account credit screen only for the matching account. Android has a credits notification channel.
- The purchased-credit expiry summary excludes membership grants. Spending history includes the unlocked item's title, and Basic cancellation maintenance only changes Basic subscriptions.
- Live payment settlement/provider integration remains deferred, so OPT-57 is complete only within the agreed non-payment scope.

### OPT-59: Plus-only content and PDF attachments

- Restored the Plus-only control on new and existing library items, with a Plus badge/filter in the admin catalog. Plus-only items do not charge credits; prior permanent unlocks are preserved.
- Added shared PDF management for all library formats: multiple files, titles, replacement, ordering and deletion, saved with the parent item. Files are restricted to PDF, 20 MB each, up to 20 per item.
- PDFs use private storage and inherit the parent's publication/access checks. App viewers obtain a short-lived signed link; account status and content access are checked again when the link is used. Locked previews omit attachment metadata.
- Library PowerSync data now contains preview fields only. Protected article bodies, sections, chapters and attachments come through the authorized detail API.
- Library export/import bundles include private PDFs and their metadata. Version 1 bundles remain supported and preserve existing destination PDFs.

### OPT-60: card badges

- Home and library card badges show the content type without duration, preserving the icon/style. Duration remains available in the detail view.

## Validation

- 63 focused Laravel tests passed (546 assertions), covering the credit ledger/reminders, library access, attachment CRUD and signed links, library discovery/media/admin behavior, authorization and export/import.
- 45 focused native/shared JavaScript tests passed, including actual attachment component interactions, notification routing, Plus/permanent-unlock access and duration-free card rendering.
- Native/shared and web TypeScript checks passed.
- Admin and production web builds passed. Existing build warnings remain.
- The installed PowerSync SQL rules parser accepted the changed rules with no errors.
- Local admin browser verification: toggled Plus on a new item, uploaded a PDF, saved/reopened the item, edited its PDF title and verified persisted values. The temporary QA item and private file were removed afterwards. The in-app browser did not render the PDF preview; response/access tests verified the protected PDF endpoint.
- `git diff --check` passed.

## Delivery and remaining verification

Changes are in the working tree; production has not been deployed. Both new migrations were applied to the local development database. Deployment requires the migrations, rebuilt clients/admin, updated PowerSync rules and the existing Laravel scheduler. Private storage must be retained/backed up with the deployment.

Push tests mock Expo requests; no live device receipt or native PDF viewer was verified. A native build/install and device smoke test are still needed for those delivery checks. Live payments require a later provider integration.

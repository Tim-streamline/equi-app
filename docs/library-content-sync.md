# Library content synchronization

Library previews remain in the automatic `public_reference` stream. Full content
uses `library_content`, parameterized by `item_id`. Its bucket identity contains
the item ID, so authorized users share the same content bucket.

`library_item_access` contains one server-managed row per user and allowed item.
These rows sync through `user_private`. The client subscribes to each allowed item;
PowerSync independently authorizes the subscription against this table using the
JWT user ID. Supplying an item ID or a forged user ID does not grant access.
Both projection tables reject client uploads.

Access follows the existing library rules: published free items, explicit credit
unlocks, and Plus items covered by an active Plus subscription. Disabled users
have no grants. Database triggers update grants in the source transaction,
including bulk SQL changes. The `library:refresh-access` command runs every minute
to reconcile scheduled publication and subscription expiry without source writes.
The Laravel scheduler must run in each deployed environment. Clock-only changes
can take up to one scheduler interval, followed by normal sync latency.

`library_contents` contains body text, ordered chapters, and ordered attachment
metadata. Database triggers maintain it when the item, chapters, or attachments
change. File paths are excluded. Audio/video files and attachment downloads still
use the existing authenticated HTTP endpoints and are not cached by this feature.

The shared mobile/web root manages subscriptions and removes them with zero TTL
when a grant disappears or the user logs out. The reader uses local SQLite and
checks the current user's grant before displaying protected content. Known grant
expiry is also enforced locally while offline. Other revocations take effect when
the device next receives them; already downloaded data cannot be remotely revoked
from a disconnected device. Granted content awaiting its first download displays
a download/offline state instead of offering another purchase.

## Deployment order

1. Deploy the backend, including `database/sql/library-sync.sql`, and run migrations.
   The migration backfills both projection tables.
2. Deploy the updated PowerSync rules and wait for the new replication snapshot
   to activate. Confirm the scheduler is running.
3. Release the updated mobile and web clients. Older clients retain their existing
   HTTP content-reading path during rollout.

## Verification

- Backend: `cd backend && ./vendor/bin/sail artisan test --compact`
- Shared client: `cd expo-app && npm test && npx --no-install tsc --noEmit`
- Web: `cd web && npm test && npm run typecheck && npm run build`
- Actual PowerSync compiler/bucket authorization (local Docker, from repository root):
  `docker exec -i backend-powersync-1 node --input-type=module < backend/tests/powersync/library-content.mjs`

Regression tests cover content projection, purchases, bulk revocation, scheduled
expiry/publication, write protection, shared bucket identity, forged subscriptions,
subscription lifecycle, and local reader behavior with offline access and stale
HTTP responses.

Local verification on 2026-10-01 passed: 318 backend tests (4,099 assertions),
188 shared-client tests, 9 web tests, both TypeScript checks, and the web build.
Chromium against the local Laravel and PowerSync services opened an article with
network access disabled, then removed its text from the open reader after the
test unlock was revoked and synchronization resumed. Native device execution and
production deployment were not part of this verification.

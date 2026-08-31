# Community

## Implemented contract

- Community is the rightmost primary tab; Account opens from the Home account icon. Old Community URLs redirect to the new screens.
- Signed-in Free users can read, bookmark, share, report and mute. Basic and Plus can also post, reply and like. Owners can delete their content or remove a like after downgrading.
- The server checks active subscriptions, start/renewal dates, disabled accounts and active community mute/ban restrictions. Accepted paid plan slugs: `basic`, `basis`, `plus`, and legacy `bundle` (which includes Plus). This change does not create a Basic billing plan or choose its price.
- Feed filters: category and a separate Bewaard list. No Mijn focus. Pinned posts first, then newest first, 20 posts per page.
- Posts contain text (10,000 characters), an optional category, up to five existing tags and up to four photos/videos. Each attachment is limited to 20 MB; supported formats: JPEG, PNG, WebP, MP4, MOV, WebM. Media is selected from the system library; video transcoding and camera capture are not added.
- Replies are chronological and flat, 50 per page. Replying to a reply stores a reference and shows its author/excerpt. Hidden or muted parent content is not quoted; deleting a parent preserves later replies and clears its reference.
- Owners can edit/delete; others can report/mute; everyone can share a discussion link. Edit requires an eligible membership and an unlocked discussion. Bookmarks have their own in-Community list. Muted authors can be restored from the Community header.
- Hidden posts and their media are unavailable in feed, bookmarks, detail and API responses. Locked discussions remain readable/bookmarkable but reject member replies and edits. Current moderation states are mutually exclusive (visible/hidden/locked/pinned).
- Therapist replies have an expert badge/preview. Vraag Shelley uses the same Basic/Plus posting entitlement and explicitly promises neither a reply nor a response time.
- Admin: existing report/moderation/expert-reply tools remain in use; private media previews and the unanswered Vraag Shelley filter are available. Hidden-reply moderation updates counters and expert-answer status.

## Architecture and deployment

Community uses authenticated `/api/community` HTTP endpoints, using the existing app JWT. Community mutations are forbidden through generic sync uploads; author identity, expert/moderation flags and counters are server controlled. The new tables are `community_bookmarks`, `community_mutes` and `community_media`; replies gain `parent_reply_id`, and posts/replies gain `edited_at`.

Attachments live on Laravel's private `local` disk, served only after user/content visibility checks. Admin media access uses the admin session and community role. JSON/media responses are private/no-store. Server/PHP/proxy upload limits must allow a multipart request containing four 20 MB files (at least 85 MB overall). Back up the private storage directory with the database.

The mobile screen refreshes on focus/foreground, pull-to-refresh, successful mutation and every 15 seconds while focused. It clears rendered server data on background, blur or request failure. Community has no offline posting or persistent content cache; temporary unsent composer text is in memory only. Draft navigation is confirmed. Already-downloaded media cannot be remotely erased from another person's device.

Deployment order:

1. Run `backend/database/migrations/2026_08_30_010000_complete_community.php` through Sail/Artisan. This removes the obsolete Mijn focus category while retaining its posts as uncategorized.
2. Deploy/reload the Laravel workers and rebuild admin assets.
3. Deploy/reload `backend/powersync/sync_rules.yaml`. Posts/replies/reactions/post-tag content is removed from the shared stream. Old clients retract this data when they next synchronize; an offline legacy client cannot be remotely cleared.
4. Rebuild/install the native mobile app: `expo-image-picker` is a new native dependency, so a JS-only update is insufficient. The existing SQLite dependency peer mismatch requires this checkout's `npm install --legacy-peer-deps` workflow; SQLite versions are unchanged.

Legacy queued Community writes are intentionally not silently discarded. An old client with a pending Community sync transaction can receive a 403 after rollout; preserve/recover its unsent content before clearing that queue or reinstalling. Coordinate app/backend rollout for existing users.

## Explicitly not decided or added

- Medical-risk disclaimer wording and automatic escalation policy. Users can flag potentially unsafe advice for the existing moderator queue, but no response-time guarantee is made.
- Push notifications, direct messages, public web sharing/landing pages, and guaranteed expert responses.
- A Basic product price or checkout flow. Subscription administration remains the existing billing implementation.

## Verification

Regression tests cover Free/Basic/Plus access, identity/expert protection, ownership, flat reply references, hidden/muted content, locked/pinned behavior, bookmarks/likes, reports, restrictions, multipart media edits/limits, deletion cleanup, pagination, authentication and rejected legacy sync writes. Mobile tests cover navigation, contextual actions, filters, timestamps and shared-discussion sign-in.

Verified on 2026-08-30:

- Full backend: 112 passing tests / 804 assertions, including 21 Community tests / 120 assertions. Initial Community tests failed on missing endpoints and the legacy sync bypass before implementation.
- Mobile tests and TypeScript pass. ESLint has no errors; seven existing warnings remain in unrelated shared UI files.
- Admin assets compile. The existing frontend Protocol terminology tests still fail two assertions in unchanged navigation/protocol files; this change does not alter them.
- Android Hermes export and x86_64 debug APK compile successfully.
- Read-only Android API 36.1 emulator: Home account navigation, rightmost Community tab, feed, bookmark persistence, replies and parent reference persistence/display, image/video upload, authenticated video playback, post edit/attachment removal, Free-mode read-only participation, report persistence, mute/unmute, shared discussion deep link, and removal of a hidden post from Bewaard verified. Temporary QA fixtures were removed afterward.
- Focused native logs: no Community crash or media playback error observed. PowerSync emitted WebSocket inactivity/reconnect warnings during the backgrounded system picker; the HTTP Community flows remained functional.

This is targeted smoke coverage, not an exhaustive device audit. Physical-device installation, iOS, production deployment, and full share-sheet delivery remain unverified. No connected physical devices were modified.

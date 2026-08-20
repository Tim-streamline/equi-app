# Admin Features Needed

This is based on the current Expo app, the PowerSync/TinyBase data model, and the Laravel backend. The app already has user-facing surfaces for onboarding, horses, protocols, observations, scanner results, library content, community, subscriptions, settings, data exports, Nova chat, and intake bookings. The backend currently exposes login, PowerSync token minting, and a generic sync upload endpoint for almost every application table.

## Current Product Surface

- Expo routes cover login/onboarding, home, protocol detail/calendar/analysis, observation logging, scanner/history/results, library article/video browsing, account, horses, subscription, community threads, and Nova chat.
- The backend models and migrations cover users, therapists, focus topics, horses, shares, stats, protocols, observations, products, ingredients, scan results, library items, seasonal tips, community, plans, subscriptions, payments, settings, data exports, chat, and intake bookings.
- PowerSync is currently configured with a single global stream that syncs all rows, including sensitive tables such as payments, notification push tokens, and data exports.
- `SyncController` accepts client CRUD for all mapped tables and notes that production authorization is still needed. That means admin functionality should be designed together with role-based access, audit logging, and per-table policies.

## Foundational Admin Requirements

### Admin Identity, Roles, and Audit

Required before exposing any back-office functions.

- Admin users separate from normal app users, or explicit admin roles on users.
- Role scopes for owner/admin, therapist, content editor, community moderator, support, and billing support.
- Two-factor authentication for privileged accounts.
- Audit log for all admin mutations, including before/after values, actor, timestamp, IP/device, and reason.
- Read-only support mode plus guarded "act as user" or impersonation with audit trails.
- Row-level access policies that match app ownership rules before client sync is trusted in production.

Why: the backend currently authenticates normal app users for sync and the upload controller trusts the JWT subject for all synced tables. Admin access cannot rely on the mobile sync path.

## User and Account Administration

Needed for support and lifecycle management.

- Search and filter users by name, email, plan, onboarding status, notification state, created date, and recent activity.
- View a user profile with linked horses, subscription, payments, data exports, notification preferences, community activity, scans, and chat sessions.
- Reset password, revoke sessions/tokens, verify or change email, disable account, and restore account where allowed.
- Edit user profile fields: name, avatar, locale, units system, notification toggle, and onboarded status.
- Manage user account settings rows shown in the app account screen.
- Start or inspect data export requests and track completed export files.
- Trigger privacy workflows: export, delete, anonymize, or retention hold.

Related tables: `users`, `notification_preferences`, `account_settings`, `data_exports`, `subscriptions`, `payments`.

## Horse and Access Administration

Needed because horse data is central and can be shared with therapists or co-carers.

- Search horses by owner, name, breed, focus topic, status, therapist, and stable.
- View a full horse profile: demographics, focus topics, shares, stats, timeline, observations, scans, protocols, and intake bookings.
- Create, edit, archive, restore, or transfer horse ownership.
- Manage `horse_shares`: invite/remove co-carers, assign therapists, set role (`full`, `read_only`), and view access history.
- Review observation notes and photos for support or therapist workflows.
- Correct derived timeline events and horse stats when data is inconsistent.

Related tables: `horses`, `horse_focus`, `focus_topics`, `horse_shares`, `horse_stats`, `timeline_events`, `observations`, `observation_photos`.

## Therapist and Expert Management

Needed because therapists author protocols, content, expert replies, shares, and intake bookings.

- CRUD for therapist profiles: name, title, bio, avatar, color, and verified state.
- Manage therapist availability, capacity, and assigned horses.
- View therapist workload: active protocols, intake bookings, expert community replies, and authored library items.
- Allow therapist-scoped admin access so experts only see assigned users/horses unless explicitly elevated.
- Manage verification badges and public/expert display data.

Related tables: `therapists`, `horse_shares`, `protocols`, `library_items`, `community_replies`, `intake_bookings`.

## Protocol Administration

Needed because protocols drive daily tasks, calendars, analysis, and user adherence.

- Create and edit protocol templates with phases, phase items, recurring tasks, analysis text, and advice cards.
- Assign a protocol to a horse and therapist, then customize title, subtitles, duration, phase state, and tasks.
- Pause, resume, complete, or archive a protocol.
- Link protocol tasks to library items for source/reference content.
- View adherence dashboards: task completion by day, missed tasks, observation frequency, and phase progress.
- Edit or delete incorrect completions and observations with audit logging.
- Recalculate current week, phase state, and calendar display data from dates instead of relying on stale labels.

Related tables: `protocols`, `protocol_phases`, `protocol_phase_items`, `protocol_analyses`, `protocol_advice`, `protocol_tasks`, `protocol_task_completions`.

## Library and Content CMS

Needed because the app has article, video, course, program, category, focus, featured, plus-gated, and seasonal content.

- CRUD for library items with format (`article`, `video`, `course`, `program`), title, description, body, video URL, hero image, author, duration, publication date, and order.
- Manage article sections and video chapters.
- Manage library categories and focus-topic tagging.
- Set featured content and plus-only gating.
- Manage seasonal tips, active windows, and CTA links to library items.
- Draft/review/publish workflow, content preview, rollback, and scheduled publishing.
- Media library for images, videos, and thumbnails.
- Engagement reporting: views, bookmarks, progress, completions, and content tied to protocol recommendations.

Related tables: `library_items`, `library_chapters`, `library_article_sections`, `library_categories`, `library_item_categories`, `library_item_focus`, `library_bookmarks`, `library_progress`, `seasonal_tips`.

## Scanner, Product, and Ingredient Administration

Needed because scanner results depend on a product catalog, ingredients, scoring, and per-horse advice.

- CRUD for products with brand, name, barcode, and category.
- CRUD for ingredients with default tag (`good`, `warn`, `danger`) and explanation.
- Manage product ingredient lists and scoring rules.
- Review scan results, photo URLs, scores, ratings, advice, and ingredient overrides.
- Flag products or ingredients that need expert review.
- Maintain natural alternatives and deep links to relevant library items.
- Support barcode merge/dedupe when the same product is scanned under multiple names.

Related tables: `products`, `ingredients`, `scan_results`, `scan_ingredients`, `library_items`.

## Community Administration and Moderation

Needed because users can post, reply, like, and receive expert answers.

- Moderate posts and replies: hide, edit, delete, lock, pin, or mark as reviewed.
- Manage reports, warnings, mutes, bans, and appeal status. New tables will be needed for reports and moderation actions.
- Manage categories and tags.
- Queue "Vraag Shelley" posts for expert response.
- Create expert replies as a therapist and mark posts as having an expert reply.
- Recalculate denormalized `likes_count`, `replies_count`, and `has_expert_reply`.
- Detect spam, duplicate posts, abusive language, medical-risk claims, and unsafe advice.

Related tables: `community_posts`, `community_replies`, `community_reactions`, `community_categories`, `community_tags`, `community_post_tags`, `therapists`.

## Nova Chat and AI Safety Administration

Needed because the app stores chat sessions and currently replies from seeded fallback content.

- Manage Nova fallback replies and their order.
- Review chat sessions and messages for quality, safety, and user support escalation.
- Add flagged-message workflows for medical-risk, self-harm-adjacent, abusive, privacy-sensitive, or out-of-scope content.
- Maintain approved knowledge snippets and references that Nova can use.
- Configure disclaimers, escalation prompts, and "ask Shelley" handoff behavior.
- Data retention controls for chat history.
- Analytics on common questions, unanswered intents, fallback frequency, and expert escalations.

Related tables: `chat_sessions`, `chat_messages`, `nova_fallback_replies`, `library_items`, `therapists`.

## Intake Booking Administration

Needed because onboarding offers a free therapist intake and the backend stores scheduled bookings.

- Manage therapist availability and bookable slots. A dedicated availability table will be needed.
- View booking calendar by therapist, status, user, horse, and date.
- Create, confirm, reschedule, cancel, complete, and no-show bookings.
- Add internal notes and user-visible notes separately.
- Send booking reminders and follow-up messages.
- Convert completed intakes into horse shares, protocols, or subscriptions.

Related tables: `intake_bookings`, `users`, `horses`, `therapists`, `horse_shares`, `protocols`.

## Subscription and Billing Administration

Needed because the app has free, plus, and bundle plans, plus payment history and cancellation UI.

- Manage plan catalog, benefits, pricing, intervals, recommended plan, and display order.
- View and change subscriptions: active, cancelled, past due, renewal date, cancellation date, max horses, and entitlement state.
- View payments, receipts, failed payments, refunds, and disputes.
- Grant manual entitlements, coupons, trials, or support credits.
- Enforce plan limits in backend policies, especially max horses, plus-only content, scans, and expert access.
- Export billing reports and reconcile payment provider events. Payment provider IDs/webhook tables will be needed.

Related tables: `plans`, `plan_benefits`, `subscriptions`, `payments`, `library_items`.

## Notifications and Messaging Administration

Needed because the app tracks protocol, community, and seasonal reminders.

- Manage push/email/in-app templates for protocol reminders, community replies, seasonal tips, bookings, billing, and export completion.
- Segment recipients by plan, focus topic, horse status, protocol state, and notification preferences.
- Send, schedule, pause, and cancel campaigns.
- Track delivery, opens, failures, unsubscribes, and invalid push tokens.
- Keep push tokens server-only in production; they should not be globally synced to every client.

Related tables: `notification_preferences`, `focus_topics`, `protocols`, `intake_bookings`, `subscriptions`.

## Data Export, Privacy, and Compliance Administration

Needed because the account screen exposes CSV/PDF data export and the product stores sensitive animal/user health-adjacent information.

- Queue, process, retry, cancel, and expire data exports.
- Generate CSV/PDF exports by user and horse.
- Track export file URLs, completion status, requester, and expiry.
- Data deletion and anonymization workflows across users, horses, observations, scans, community, billing, chat, and exports.
- Consent and terms-version tracking. New tables will be needed.
- Retention policies for photos, chats, exports, logs, and deleted accounts.

Related tables: `data_exports`, `users`, `horses`, `observations`, `scan_results`, `chat_sessions`, `community_posts`.

## Operations, Analytics, and Data Quality

Needed to run the app safely after launch.

- Dashboard for signups, onboarded users, active horses, protocols, task adherence, scans, library engagement, community activity, bookings, subscriptions, and revenue.
- Sync health dashboard for PowerSync status, upload failures, skipped unknown tables, latency, and global/per-user stream coverage.
- Queue/job monitoring for exports, notifications, billing webhooks, media processing, and scheduled content.
- Data repair tools for denormalized counters and display labels.
- Duplicate detection for users, horses, products, ingredients, and library content.
- Admin logs for failed logins, permission denials, destructive actions, and sync conflicts.

Related backend pieces: `powersync/sync_rules.yaml`, `SyncController`, queue/job tables, application logs.

## Backend Hardening Needed Before Admin Launch

These are not optional if the admin tool will touch production data.

- Replace the global PowerSync stream with per-user streams and separate public/reference streams.
- Remove sensitive server-owned tables from client sync: payments, push tokens, data exports, and any future moderation or audit tables.
- Add per-table write policies in `SyncController` so users cannot mutate reference content, billing, other users' data, or admin-owned tables.
- Add admin-only API routes instead of routing admin writes through `/api/sync/upload`.
- Add policies for horse ownership and therapist shares.
- Add audit-log tables and soft-delete/restore behavior for destructive admin actions.
- Add validation rules per synced table rather than generic `fill($data)`.
- Add file/media handling for observation photos, library media, scan photos, receipts, and exports.

## Suggested MVP Order

1. Security foundation: admin auth, roles, policies, audit log, and PowerSync stream hardening.
2. Support console: user search, user detail, horse detail, shares, observations, exports, and account status.
3. Content/admin console: library CMS, seasonal tips, focus topics, products, ingredients, and Nova fallback replies.
4. Community and therapist console: moderation queue, expert replies, therapist profiles, and intake booking management.
5. Protocol console: template builder, assign/customize protocol, task calendar review, and adherence analytics.
6. Billing and operations: subscriptions, payments, entitlements, dashboards, sync health, and data repair tools.

## Tables That Likely Need To Be Added

- `admin_users` or user role tables (`roles`, `permissions`, `model_has_roles`).
- `audit_logs` for admin and privileged sync mutations.
- `moderation_reports`, `moderation_actions`, and possibly `user_restrictions`.
- `therapist_availability` and `booking_slots`.
- `protocol_templates`, `protocol_template_phases`, and `protocol_template_tasks`.
- `notification_templates`, `notification_campaigns`, and `notification_deliveries`.
- `billing_provider_events`, `coupons`, and `entitlement_overrides`.
- `media_assets` for reusable uploaded files.
- `consents`, `data_deletion_requests`, and `retention_jobs`.

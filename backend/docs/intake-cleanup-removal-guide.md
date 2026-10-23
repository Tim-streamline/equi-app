# Intake cleanup and compatibility removal guide

## Purpose and scope

The app is still in its test phase. The cleanup and compatibility code below was added to preserve existing test answers and safety history while the intake model changed. It should not become permanent application complexity merely to support that test data.

This document inventories that code so it can be removed deliberately later. **It does not remove code or authorize deleting data now.** “Recovery” in the sync code means recovering an offline answer edit after its database row was deleted. It is separate from the retired medical recovery-question workflow.

Verified from the source on **2026-10-23**, branch `shelley`, HEAD `29246d2`, against `25575b37dedf903cb443ba6328bb67299148f5d9` (the supplied `origin/master` comparison base). This covers intake cleanup, conversions and compatibility introduced in these changes, not unrelated feed-catalog backfills or older repository migrations. Symbol names are included because line numbers will move.

## Current product behavior to keep

The intended current flow is:

1. A question option can have configurable `submission_blocks` rules and explanatory text.
2. Matching current answers in that intake block submission. The client shows the messages above the submission button; the server also enforces the rules.
3. Changing to a non-blocking answer removes that block without a separate recovery declaration.
4. Protocol preparation requires a submitted intake. It does not independently revive the old horse-level recovery workflow.

Keep [IntakeSubmissionBlocks.php](/home/tim/repos/equi-app/backend/app/Support/IntakeSubmissionBlocks.php), its call from [IntakeSubmission.php](/home/tim/repos/equi-app/backend/app/Support/IntakeSubmission.php), [submission-blocks.ts](/home/tim/repos/equi-app/expo-app/lib/intake/submission-blocks.ts), [SubmissionBlockNotice.tsx](/home/tim/repos/equi-app/expo-app/components/intake/SubmissionBlockNotice.tsx), and the submitted-intake checks in [Protocol.php](/home/tim/repos/equi-app/backend/app/Models/Protocol.php) and [ProtocolPreview.php](/home/tim/repos/equi-app/backend/app/Support/ProtocolPreview.php).

Also keep ordinary ownership authorization, submitted/completed-intake immutability, upload handling, offline queue acknowledgement, structured date validation/clearing, and stable repeater row IDs. Those are current application behavior, not test-data migration workarounds.

## 1. Deleting obsolete answers and reopening intakes

**Main file:** [IntakeObsoleteAnswers.php](/home/tim/repos/equi-app/backend/app/Support/IntakeObsoleteAnswers.php).

### One-time cleanup: `run()`

Called by [2026_10_22_000005_clear_obsolete_intake_answers.php](/home/tim/repos/equi-app/backend/database/migrations/2026_10_22_000005_clear_obsolete_intake_answers.php).

- Finds active fields in active sections of `protocol-intake`, respecting `answer_section_key` for moved questions.
- Runs the safety-report backfill before deleting source answers.
- Locks each horse and its intake bookings, finds obsolete choices, and deletes the affected answer rows directly without model observers.
- Reopens affected submitted/completed intakes by clearing `submitted_at` and `completed_at` and setting `intake_status` to `draft`. It keeps other answers, attachments, protocols and review/delivery history.
- Before deletion, preserves removed trigger values in historical unresolved safety reports. It can replace obsolete pinned recovery requirements with configured current choices, clear the corresponding old confirmation and refresh the recovery-field snapshot. It does not guess a confirming answer when no compatible replacement exists.
- Reprojects the historical reports into `horses.laminitis_safety` without touching the horse activity timestamp.

### Validity helper: `obsolete()`

- Radio: a nonempty value must match a current option.
- Multi-select: every selected value must match; one invalid selection makes the **whole answer row** obsolete.
- Number: nonnumeric string fallback answers must match a current option, except fields using `age_config`.
- Configured aliases are resolved for comparison only; valid historical stored values remain unchanged.
- Empty values are not classified as obsolete by this helper. Other field types are not checked by it.

This is **not** a universal sweep of every removed question or nested repeater option. `run()` only evaluates answers with a matching active field. Answers for missing/inactive questions are not deleted by that sweep.

### Ongoing cleanup: `discardObsoleteAnswer()`

[SyncController.php](/home/tim/repos/equi-app/backend/app/Http/Controllers/SyncController.php), `upload()`, calls this after applying an intake-answer operation. It deletes newly uploaded obsolete choices so an old offline device cannot restore an answer the cleanup removed. It does not perform the full backfill/reopening procedure on every upload.

**Removal boundary:** the migration-only `run()` machinery and the runtime discard hook are separate pieces. Removing the migration does not remove the runtime behavior. Removing the class alone breaks both migrations and sync recovery. If automatic deletion of removed choices is no longer wanted after the test-data reset, remove the hook, the recovery dependencies described next, and the helper together. If option validation is wanted permanently, implement that as an explicit current validation policy rather than retaining the historical repair workflow by accident.

## 2. Recovering offline edits to deleted answer rows

This was added because cleanup can delete answer A on the server while a device still has A locally and queues a valid correction to it.

| Location | Added compatibility behavior |
| --- | --- |
| [sync-operations.ts](/home/tim/repos/equi-app/expo-app/lib/intake/sync-operations.ts), `prepareSyncOperations()` | Adds `response_id`, `section_id`, `field_id` to intake-answer PATCH operations without replacing the queued answer value. Uses operation data, then tracked previous values, then a local-row lookup for writes queued by older clients. |
| [powersync-schema.ts](/home/tim/repos/equi-app/expo-app/db/powersync-schema.ts), `intake_answers.trackPrevious` | Tracks the previous question identity even when those columns did not change. Supplies identity after a local row has disappeared. |
| [native connector](/home/tim/repos/equi-app/expo-app/db/connector.ts) and [web connector](/home/tim/repos/equi-app/web/db/connector.ts) | Both call `prepareSyncOperations()` before uploading a batch. |
| [SyncController.php](/home/tim/repos/equi-app/backend/app/Http/Controllers/SyncController.php), `upload()` | Detects missing-row PATCHes before authorization and again after acquiring the booking lock, because cleanup may remove the row while the request waits. |
| Same controller, `validateAnswerRecovery()` | Requires question identity; returns HTTP 409 if it is missing so the client does not acknowledge and lose an unidentifiable correction. Validates IDs and JSON value. |

For a recoverable PATCH, the server:

1. Applies ownership authorization and the booking lock. Submitted/completed bookings skip the write; cancelled bookings cannot be recovered.
2. Looks up the active question through `IntakeObsoleteAnswers::fieldFor()`.
3. Skips empty, obsolete or unconfigured answers, including metadata-only PATCHes.
4. Finds an existing replacement answer for the same `(response_id, section_id, field_id)` or reuses the original UUID.
5. Converts the operation to a PUT with only identity and value fields, then applies it.

The controller also acknowledges DELETEs whose original answer UUID is already absent.

### Known limitation still present at this snapshot

If another device has created replacement row B, an offline PATCH to deleted A can update B. A later queued DELETE of A is then skipped because A does not exist. B survives and can reappear on the device. `prepareSyncOperations()` currently enriches PATCH only, even though previous-value tracking can provide DELETE identity too.

This guide records the existing issue; it does not fix it. If this compatibility path remains, that sequence needs a fix and regression test. If the path is retired with the test data, make sure no old client queue can replay that sequence afterward.

**Removal boundary:** remove missing-row recovery, its validation method, post-lock recovery checks and cleanup-specific missing-DELETE handling as a coordinated change with both connectors and identity tracking. A simpler wire-operation mapper may still be useful; the helper need not be retained solely for recovery. Do not remove generic authorization, booking locks, completed-intake restrictions, attachment uploads, session checks, or successful-upload-only queue acknowledgement.

## 3. Historical safety report backfill and retired recovery engine

### Backfill

[IntakeSafetyReportBackfill.php](/home/tim/repos/equi-app/backend/app/Support/IntakeSafetyReportBackfill.php), `run()`:

- Imports an old single horse-level hold into `intake_safety_reports` if no report exists yet.
- Expands saved episode history, preserving separate concerns in the same intake when their trigger kind or pinned configuration differs.
- Reconstructs missing reports from both draft and submitted intake answers using current and historical configurations.
- Preserves recorded releases rather than recreating a hold solely from historical answers.
- Uses horse locks and updates the compatibility projection without rewriting answer/attachment/booking data or horse activity timestamps.

Called by migrations `2026_10_22_000002`, `000003`, `000004`, and by `IntakeObsoleteAnswers::run()`.

### Retained engine

[LaminitisSafety.php](/home/tim/repos/equi-app/backend/app/Support/LaminitisSafety.php) still contains:

- Legacy import and configuration/requirement pinning: `importLegacy()`, `legacyReportState()`, `recoveryFields()`, `mergeRequirements()`.
- Projection into the old horse JSON: `project()`.
- Old report creation/renewal and release logic: `observe()`, `renew()`, `release()`.
- Old state and gating helpers: `blocked()`, `withState()`, `releasedCritical()`, `message()`, `assertAllowed()`.

The normal answer model no longer calls its safety observer, and normal submission/protocol paths no longer call its old gating methods. The class remains for historical migrations/repairs and tests. The earlier recovery endpoint is not present in the current routes.

[IntakeSafetySettings.php](/home/tim/repos/equi-app/backend/app/Support/IntakeSafetySettings.php) still validates/evaluates the old workflow configuration. **It also contains `validateAliases()`, used by the current questionnaire admin and snapshot import.** Move or retain that method if aliases stay; do not delete the class without handling those callers.

### Remaining storage and configuration plumbing

- [IntakeSafetyReport.php](/home/tim/repos/equi-app/backend/app/Models/IntakeSafetyReport.php), `intake_safety_reports`, and `safetyReports()` relationships on [Horse.php](/home/tim/repos/equi-app/backend/app/Models/Horse.php) and [IntakeBooking.php](/home/tim/repos/equi-app/backend/app/Models/IntakeBooking.php).
- `horses.laminitis_safety` cast and its column in the shared client PowerSync schema.
- `intake_questionnaires.safety_config` fillable/cast, snapshot import/export handling and shared client schema column.
- [IntakeQuestionnaireController.php](/home/tim/repos/equi-app/backend/app/Http/Controllers/Admin/IntakeQuestionnaireController.php) still accepts and validates `safety_config_json`, although the current admin page does not expose the old editor.
- [intake-safety-2026-10-22.json](/home/tim/repos/equi-app/backend/database/data/intake-safety-2026-10-22.json), [laminitis-2026-10-21.json](/home/tim/repos/equi-app/backend/database/data/laminitis-2026-10-21.json), and [intake-safety.json test fixture](/home/tim/repos/equi-app/backend/tests/Fixtures/intake-safety.json).

**Removal boundary:** historical storage can remain read-only after executable recovery logic is removed. Dropping that storage is a separate data decision. Migrations must first stop depending on these classes; otherwise a fresh installation or upgrade fails even if the live application no longer calls them.

## 4. Dated migrations and their data transformations

These files mix definition/schema changes with test-data preservation. Keep their current schema and questionnaire result when simplifying the migration chain.

| Migration (all under `backend/database/migrations/`) | Existing-data behavior to retire or isolate |
| --- | --- |
| `2026_10_21_000001_branch_deworming_questions.php` | Rewrites two historical deworming option strings to their new wording, including answer `updated_at`. Also changes definitions/branching. |
| `2026_10_21_000008_refine_suspicions_and_kpu_results.php` | Copies old EMS selections and accompanying text into the replacement explanation if absent; retains originals. Creates KPU “result available: Ja” from existing attachment references. Uses `withoutEvents()` to avoid later-schema observers. Also adds current `signal_config` and definitions. |
| `2026_10_21_000010_configure_laminitis_safety.php` | Adds horse safety JSON, initializes conservative old holds from medical answers, translates four historical status labels, installs/retires medical fields. |
| `2026_10_21_000011_structure_intake_dates.php` | Adds `date_config` and current date definitions; converts old single and repeater date values with `IntakeDates::forField()`. Preserves non-date row properties and answer timestamps. |
| `2026_10_21_000012_merge_roughage_gap_questions.php` | Retires old roughage-gap fields; derives a new duration choice where old numeric/presence answers are unambiguous. Leaves contradictory answers for the owner to answer again, retains original rows, and avoids overwriting a replacement answer. |
| `2026_10_22_000001_configure_intake_safety_rules.php` | Adds `safety_config` and `answer_aliases`; incorporates locally configured trigger values, adds deworming aliases, and pins configuration/requirements/field snapshots onto old holds. |
| `2026_10_22_000002_create_intake_safety_reports.php` | Creates report storage and runs the historical backfill. |
| `2026_10_22_000003_backfill_missing_intake_safety_reports.php` | Reruns backfill to recover concerns missed by the earlier import. |
| `2026_10_22_000004_restore_distinct_intake_safety_concerns.php` | Reruns backfill to repair earlier grouping that collapsed distinct concerns within one intake. |
| `2026_10_22_000005_clear_obsolete_intake_answers.php` | Invokes the cleanup/reopening/old-requirement repair described in section 1. |
| `2026_10_22_000006_replace_recovery_with_answer_blocks.php` | Adds `submission_blocks`, converts configured old triggers into option/message rules while preserving other trigger conditions, retires workflow-specific fields gated by the old state, and clears `safety_config`. Leaves answers and reports as historical data. |

Other intake migrations in this branch (`2026_10_21_000002`, `000004`–`000007`, `000009`) primarily alter definitions rather than repair answer rows. They still contain old labels in branching definitions in places; those definitions can be consolidated into the chosen current questionnaire baseline.

Many `down()` methods deliberately preserve data instead of undoing transformations. Removing or rolling back a migration does not automatically restore its earlier answer contents. Several migrations call present-day application helpers, so deleting those helpers without revising the migration strategy breaks replay.

## 5. Answer aliases and old snapshot compatibility

Aliases are configurable mappings from an old stored answer string to a current option. They preserve raw answers and are useful beyond test-data migration, so decide separately whether to retain this feature.

The complete path includes:

- `answer_aliases` on [IntakeField.php](/home/tim/repos/equi-app/backend/app/Models/IntakeField.php), questionnaire admin validation/editor, [IntakeQuestionnaireSnapshot.php](/home/tim/repos/equi-app/backend/app/Support/IntakeQuestionnaireSnapshot.php), and [IntakeQuestionnaireSeeder.php](/home/tim/repos/equi-app/backend/database/seeders/IntakeQuestionnaireSeeder.php).
- Shared client schema and [config.ts](/home/tim/repos/equi-app/expo-app/lib/intake/config.ts), `answerAliases` in [schema.ts](/home/tim/repos/equi-app/expo-app/lib/intake/schema.ts), and answer resolution in [logic.ts](/home/tim/repos/equi-app/expo-app/lib/intake/logic.ts).
- Backend [IntakeReview.php](/home/tim/repos/equi-app/backend/app/Support/IntakeReview.php), whose resolved answers also feed submission-block evaluation.
- The obsolete-answer validator, which must not delete an old value that maps to a valid current option.
- Saved questionnaire JSON definitions, including the local snapshot.

The controller preserves aliases when an older form omits them; explicit null clears them. Snapshot validation allows older snapshots to omit newly added settings, and import preserves omitted settings. That compatibility includes `safety_config`, `answer_aliases`, `signal_config`, `date_config`, and `submission_blocks`; the latter three are still current features.

**Removal boundary:** if historical values and old snapshots are discarded, alias-specific support and omitted-setting compatibility can be simplified. Do not delete current date/signal/submission settings themselves. If aliases remain, keep their rendering, branching, review and submission behavior consistent across client and server.

## 6. Legacy dates and repeater row IDs

### Old date formats

[IntakeDates.php](/home/tim/repos/equi-app/backend/app/Support/IntakeDates.php), `normalize()`/`forField()`, and [dates.ts](/home/tim/repos/equi-app/expo-app/lib/intake/dates.ts), `normalizeDate()`, parse historical strings. Unambiguous month/year/day values become structured values; ambiguous text becomes `{version: 1, precision: 'legacy', text: ...}`. Exact historical dates keep day precision.

This is not only a one-time migration: the [IntakeAnswer.php](/home/tim/repos/equi-app/backend/app/Models/IntakeAnswer.php) saving hook normalizes date/repeater answers, and client rendering/completion plus backend review/completion accept old representations. [MonthYearField.tsx](/home/tim/repos/equi-app/expo-app/components/intake/MonthYearField.tsx) shows “Eerder ingevuld” text and an explicit replacement action, and displays previously entered exact dates.

**Removal boundary:** after old date strings/legacy objects are gone, remove historical parsing, legacy completion/formatting branches and old-value UI together. Keep the structured month/year/range format, unknown/year-only behavior, optional/required validation, and empty-control clearing fixes. Removing whole date helpers would remove current functionality too.

### Rows created before IDs existed

[repeater.ts](/home/tim/repos/equi-app/expo-app/lib/intake/repeater.ts), `withRepeaterRowIds()`, supplies missing/duplicate `_rowId` values. A WeakMap holds IDs for legacy row objects without modifying answers just by viewing them; the next ordinary edit persists IDs. [IntakeField.tsx](/home/tim/repos/equi-app/expo-app/components/intake/IntakeField.tsx) uses those IDs for keys, updates and deletion.

**Removal boundary:** once every saved row has an ID, the missing-ID adoption/WeakMap logic may be simplified. Keep ID creation for new rows and ID-based rendering/editing. Reverting to position-based keys would reintroduce the remembered-date transfer bug. Duplicate-ID handling is also useful defensive behavior independently of migration.

## 7. Tests and documentation to revisit

| File or suite | Removal treatment |
| --- | --- |
| [IntakeObsoleteAnswersTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeObsoleteAnswersTest.php) | Cleanup, reopening, aliases and pinned old recovery evidence. Retire cleanup-only scenarios when that feature is removed; retain alias coverage if aliases stay. |
| [IntakeSyncAuthorizationTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeSyncAuthorizationTest.php) | Mixed suite: remove/rewrite missing-row correction, cleanup-race, replacement-row, obsolete-upload and recovery-only tests. Keep ownership, answer reassignment, submitted-intake restrictions and normal sync/submission coverage. |
| [intake-offline-correction.test.mjs](/home/tim/repos/equi-app/expo-app/tests/intake-offline-correction.test.mjs) | Tests identity tracking, operation enrichment, old queued writes and both connectors. Mostly specific to the cleanup recovery path. |
| [offline-attachments.test.mjs](/home/tim/repos/equi-app/expo-app/tests/offline-attachments.test.mjs), [subscription-sync.test.mjs](/home/tim/repos/equi-app/expo-app/tests/subscription-sync.test.mjs) | Keep suites; update imports/mocks if the shared operation helper changes. |
| [IntakeSafetyUpgradeTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeSafetyUpgradeTest.php), [ConfigurableIntakeSafetyTest.php](/home/tim/repos/equi-app/backend/tests/Feature/ConfigurableIntakeSafetyTest.php) | Mostly historical migration/repair/retired workflow coverage. Retain while old upgrades are supported; replace with fresh-baseline coverage if that support is intentionally dropped. Move still-needed alias/admin/snapshot cases before deleting suites. |
| [IntakeSubmissionBlocksTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeSubmissionBlocksTest.php), [LaminitisSafetyTest.php](/home/tim/repos/equi-app/backend/tests/Feature/LaminitisSafetyTest.php) | Keep current behavior tests. Despite its old filename, `LaminitisSafetyTest` now checks answer-based submission blocking. |
| [IntakeDateFieldsTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeDateFieldsTest.php), [intake-dates.test.mjs](/home/tim/repos/equi-app/expo-app/tests/intake-dates.test.mjs), [intake-controls.test.mjs](/home/tim/repos/equi-app/expo-app/tests/intake-controls.test.mjs) | Remove only legacy conversion cases if support is dropped; keep structured date validation and clearing regressions. Update shared [intake-dates.json](/home/tim/repos/equi-app/backend/tests/Fixtures/intake-dates.json) accordingly. |
| [intake-repeater-identity.test.mjs](/home/tim/repos/equi-app/expo-app/tests/intake-repeater-identity.test.mjs) | Keep deletion/reordering/date-memory tests. Rewrite only adoption scenarios if pre-ID rows become unsupported. |
| [IntakeSuspicionSignalsTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeSuspicionSignalsTest.php), [IntakeRoughageGapsTest.php](/home/tim/repos/equi-app/backend/tests/Feature/IntakeRoughageGapsTest.php) | Separate answer-conversion checks from current signal/question behavior before retiring migrations. |

[intake-safety-settings.md](/home/tim/repos/equi-app/backend/docs/intake-safety-settings.md) and [laminitis-safety.md](/home/tim/repos/equi-app/backend/docs/laminitis-safety.md) still describe the former persistent recovery workflow as active. Treat them as historical descriptions, not the current product contract. Replace/archive them when removing the retained engine. Update the compatibility portions of [intake-dates.md](/home/tim/repos/equi-app/backend/docs/intake-dates.md) if historical date support is removed.

## 8. Suggested removal sequence

- [ ] Choose whether future installations must still upgrade databases from `origin/master`, or whether test environments will start from a new current baseline. Record that decision before removing migrations.
- [ ] Agree which test data to retain/export and which to reset. Include local web/native databases and queued offline writes; a server-only reset does not remove an old client's queue. This document does not authorize a reset.
- [ ] Ensure the fresh schema, seed and any supported snapshot import directly produce current questions and `submission_blocks` messages without needing the retired recovery workflow as an intermediate step.
- [ ] Remove server missing-row correction and ongoing obsolete-answer deletion together with client enrichment/previous-value tracking once old queued writes are no longer supported.
- [ ] Remove or isolate one-time answer cleanup and safety backfills. If migration replay is retained, keep their dependencies or make those historical migrations self-contained first.
- [ ] Remove the retired recovery engine and configuration plumbing. Preserve/move alias validation if aliases remain.
- [ ] Decide separately whether historical report storage, aliases, old date formats and legacy-row adoption are still needed; remove only the selected compatibility layers.
- [ ] Update the tests and stale docs listed above. Do not remove a whole mixed test suite solely because its name contains “safety” or “recovery.”
- [ ] Verify a fresh database installation and each upgrade path still claimed as supported, using disposable databases.
- [ ] Verify normal native/web answer creation, editing, clearing and sync; unauthorized writes; submitted-intake immutability; configurable blocking messages; submission-to-protocol gating; optional/required date clearing; and stable row state after deletion/reordering.
- [ ] Search for dangling references after removal. In particular, migrations and snapshot/admin code can still reference a helper even when normal app flows do not.

Useful source searches from the repository root:

```bash
rg -n 'IntakeObsoleteAnswers|IntakeSafetyReportBackfill|LaminitisSafety|IntakeSafetySettings' backend
rg -n 'recoverAnswer|validateAnswerRecovery|prepareSyncOperations|trackPrevious' backend/app expo-app web
rg -n 'safety_config|laminitis_safety|intake_safety_reports|safetyReports' backend expo-app web
rg -n 'answer_aliases|answerAliases|normalizeDate|legacyIds' backend/app backend/database expo-app web
```

No application code or data was changed while preparing this inventory. The inventory was checked against source; migrations and test suites were not executed for this documentation-only change.

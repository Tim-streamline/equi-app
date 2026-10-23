# Laminitis intake safety

Question definitions and warning text are editable intake fields in the admin. The
dated migration configures the existing questionnaire without running a seed or
resetting answers. Retired questions remain stored but inactive. Legacy status
labels are translated to equivalent readable labels; uncertain old episode counts
are preserved and must be checked against the new answer choices.

`horses.laminitis_safety` is server-owned JSON, excluded from mass assignment.
An acute answer, or an explicit not-recovered/unknown answer after an earlier
episode, creates a persistent hold. Editing or deleting intake answers, resetting
a section, or opening another intake does not remove the hold. The data records
the episode UUID, source booking, reporter and timestamp, and retains prior episodes.
Existing acute answers receive a hold during migration, including submitted intakes.

The client saves an opaque pending-event marker before the acute answer so the
hold is visible offline and survives restarting. The server independently records
its own episode UUID. The marker does not grant release permission. Trigger values, recovery conditions and notice placement now come from
`intake_questionnaires.safety_config`. Existing configured values and legacy aliases
are carried over by migration. See [configurable safety settings](intake-safety-settings.md).

Recovery requires the configured confirmation answers and a separate confirmation request
with the current server episode UUID. Stale confirmation requests cannot release
a newer episode. The release records an owner declaration of veterinary stability,
not an independently verified veterinary statement. A prior not-recovered/unknown
answer must also be updated to recovered. Submitted intakes use separate current
declarations, preserving the submitted answers. New acute reports reset release.

Draft saving remains available. `IntakeSubmission` rejects submission while held;
`Protocol::save` rejects configuring/publishing/activating a protocol and
`ProtocolPreview` rejects generating previews while held. Empty purchase/intake
drafts remain possible. There is no autonomous AI generation entry point in this
implementation; a future generator must call `LaminitisSafety::assertAllowed` too.
The admin intake review includes the hold and owner release metadata.

PowerSync already selects all horse columns within ownership/share boundaries;
the client schema adds the JSON column without widening sync rules.
Tests use the isolated `testing` database. The local migration is preceded by a
database dump in `storage/app/private/`.

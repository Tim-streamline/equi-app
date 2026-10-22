# Configurable intake safety

`intake_questionnaires.safety_config` configures the existing persistent horse-level
safety workflow. It is editable under **Veiligheidsregels (JSON)** in the questionnaire
admin and included in questionnaire snapshots and PowerSync. Version 1 supports one
workflow per questionnaire, with multiple alternative triggers. The existing
`horses.laminitis_safety` column and recovery endpoint remain for client compatibility.
No condition-specific question IDs or answer text are interpreted by runtime code.

The configuration contains:

- `section`: the answer namespace containing the workflow questions.
- `marker_field`: an internal key starting with `_` for pending offline reports.
- `state_field`, `state_value`, `clear_value`: a computed answer exposed to ordinary
  `show_if` rules. This answer is not persisted as an owner declaration.
- `actions`: any of `submit`, `preview`, `protocol` to block while a hold is open.
- `triggers`: ordered rules with a stable `id`, `when`, `renew`,
  `block_while_matching`, `notice`, `popup` and `critical_fields`.
  `when` maps field keys to accepted stored string values. All keys must match;
  alternatives within a value list are OR conditions. Only changes to participating
  fields start a report. `renew` starts a new episode even during an existing hold.
  `block_while_matching` requires the trigger to clear before release. `notice`
  selects an ordinary questionnaire field's label/hint. `critical_fields` identifies
  review flags covered by an explicit release of that trigger.
- `recovery.require`: field keys and accepted confirmation values. Conditional
  requirements use the same `when` syntax in `recovery.conditional` entries.
  `recovery.after` places the confirmation panel after a configured field; the
  overview also exposes confirmation when fields have since been retired.
  `recovery.intro` identifies the configured explanation field.
- `messages`: editable blocking, confirmation, update, acknowledgment and pending copy.
- `release_source`: audit metadata stamped by the server, never by the caller.

See `database/data/intake-safety-2026-10-22.json` for the migrated configuration and
`tests/Fixtures/intake-safety.json` for an example with unrelated keys and values.
Choice values remain the existing strings: changing a recovery value requires updating
its configured accepted values and question options. Question wording can be edited
independently. This change does not rewrite existing answers into new option IDs.

## Existing data and episodes

Migration `2026_10_22_000001` adds settings and aliases and copies the old rules,
including existing locally configured acute/past choices. It does not change answer
bytes, attachment references, episode IDs, release state, history or activity timestamps.
It adds configuration, requirements and recovery question snapshots to existing holds.
The migration is repeatable and its rollback intentionally retains this information.

Every new episode similarly pins its configuration, conditional requirements and
recovery labels/options. Disabling or changing questionnaire rules cannot release a
saved hold or make it depend on replacement answer labels. A release requires the
current server episode UUID and all pinned confirmations. Post-submission declarations
are kept separately from submitted answers. Deleting an answer or starting another
intake does not remove the hold or its pinned conditional requirements.

Old installed clients retain their built-in UI rules. Update clients alongside the
backend before introducing different safety question keys or answer values. The server
continues to enforce the stored rules, but old clients may be unable to complete a
newly configured recovery flow.

## Answer aliases

`intake_fields.answer_aliases` maps historical answer values to current values for
rendering, branching and review. The admin exposes **Oude antwoordwaarden (JSON)**.
The original answer map and database values remain unchanged. Aliases cannot chain
or form cycles. Omission by an older admin form or snapshot preserves the setting;
explicit null clears it. Safety conditions deliberately use the stored values,
including any required legacy values, so changing display aliases cannot reinterpret
an existing episode's safety contract.

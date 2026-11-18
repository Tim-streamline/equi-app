# Intake labels

Labels are editable admin metadata on `intake_fields.label_config`. They do not
classify submitted answers, trigger advice, or change questionnaire visibility.
Existing question keys and answer strings remain the storage identity.

The migration reads the current database (including archived fields), not a
bundled questionnaire definition. It adds a question label and one label per
configured answer option. Repeater subfields are included by their nested ID
path. Section headings have no labels. Numbers, dates, uploads and free text
retain their actual values; labels do not interpret those values.

Each config entry has `key` (empty for the parent question), `label`, and
`answers` (a list of `{answer, label}`). Labels are scoped to their field and
must be distinct within that field, including its subfields. A future consumer
must identify a label together with the field ID, not assume global uniqueness.

Admin copy edits and answer reordering retain existing labels by exact answer
text. Added options get defaults on save; removed options leave the config.
Renaming an answer is a new option: check its label when changing the answer.
Renaming the question's visible text does not rename its label. Empty question
labels get a default; clearing an answer label regenerates its default.

Deploy the migration before serving the new admin code. No existing intake
answers or protocols are backfilled or modified. Rollback drops only the label
metadata column (including manually edited labels).

Validation: `php artisan test --filter=IntakeLabelsTest` and
`node --test tests/frontend/intakeLabels.test.js`.

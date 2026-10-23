# Owner-reported suspicion signals

IR, EMS and KPU suspicion explanations are ordinary admin-configured text questions.
`intake_fields.signal_config` holds the condition context and an editable dictionary
of stable signal codes with descriptions. Context identifies which question was
answered; it does not establish a condition or diagnosis. The field placeholder
remains ordinary editable questionnaire copy.

No AI request, automatic keyword extraction, diagnosis or protocol advice is enabled.
The admin review labels these answers as an unconfirmed owner suspicion. Fields with
signal configuration do not generate `protocol_if` advice triggers.

`IntakeReview.owner_reported_inputs` prepares only visible, non-empty explanations
for a future extractor. Each includes original text, answer reference, context,
allowed signals and a hash covering the text and configuration. Treat the text as
untrusted data, including any instructions embedded by an owner.

Future extractor output must pass `IntakeSignalPreparation::normalize()` using the
current field, answer text and answer section. The accepted shape is:

```json
{
  "source_hash": "hash from the prepared input",
  "signals": [
    {"code": "vetkam", "evidence": "geen vetkam", "polarity": "absent"},
    {"code": "moeite_met_afvallen", "evidence": "valt moeilijk af", "polarity": "present"}
  ]
}
```

Every evidence fragment must occur literally in the current text. Unknown codes,
duplicate codes, stale hashes, fabricated evidence, diagnosis fields and model-supplied
provenance are rejected. The server stamps `source=owner_reported`,
`clinical_status=unconfirmed` and `review_status=unreviewed`. `polarity` preserves
negation and uncertainty (`present`, `absent`, `uncertain`); negated or uncertain
mentions must never become positive signals merely because a keyword occurs.

These checks constrain structure and provenance; they cannot verify an AI's semantic
interpretation. A future integration must test negation, time references and ambiguity,
and define clinical review before using observations in protocol decisions. Reported
signals never constitute a veterinary diagnosis, including when their context is IR,
EMS or KPU. Extraction results should be stored separately from original answers and
must be invalidated when their source hash changes. No result storage is introduced yet.

Migration `2026_10_21_000008` archives the old EMS multi-choice definitions and retains
their original answers. It copies literal selections and any selected "other" detail
to the new open field without overwriting an existing explanation. Existing KPU
upload references are preserved and default availability to Yes only when no answer
to availability exists. Offline clients may still submit retired EMS keys; these stay
stored, while the current open question must be answered in the updated client.

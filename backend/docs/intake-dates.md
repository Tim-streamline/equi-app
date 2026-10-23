# Structured dates in protocol intakes

The admin field type `month_year` renders the compact month selector, numeric
year and “Weet ik niet” checkbox. The field hint accepts estimates. There is no
separate estimate checkbox. Month precision never implies a known day or an
exactly remembered event. “Maand onbekend” supports year-only answers without
inventing a month. Birth dates, photo years, durations and frequency questions
retain their original controls.

`date_config` controls `mode` (`single` or `range`), `allow_future`,
`allow_year_only`, `min_year`, and optional month/year/unknown/ongoing labels.
The same settings are available as `dateConfig` on repeater subfields. The admin
can edit questions, hints, branches and these settings; runtime code does not
override questionnaire definitions.

New answer examples:

```json
{"version":1,"precision":"month","year":2026,"month":7}
{"version":1,"precision":"year","year":2025}
{"version":1,"precision":"unknown"}
{"version":1,"precision":"range","start":{"version":1,"precision":"month","year":2025,"month":2},"ongoing":true}
```

The dated migration configures relevant questions across horse details,
complaints, history, medical care, feeding and training. Mixed narrative/date
questions gain a separate conditional date question; their original text stays
under its original answer key. Repeater entries keep all other properties.

Only unambiguous old date strings are converted automatically. Exact dates retain
day precision. Ambiguous text becomes an explicit legacy answer, for example:

```json
{"version":1,"precision":"legacy","text":"voorjaar 2024"}
```

Legacy answers stay readable and count as previously completed; replacing one
requires an explicit action. An AI consumer must treat legacy text as unstructured
and must not fabricate a month/day or interpret unknown as a negative finding.
There is no automatic inference or date extraction model call. No new unknown
answers are invented for previously blank fields.

App and backend validators share examples in `tests/Fixtures/intake-dates.json`.
Partial dates, invalid months, prohibited future months and reversed ranges do
not count as complete. Previously optional repeater subfields stay optional;
provided date subfields must be valid. Admin/PDF review rows contain human-readable
`value` and retain raw structured dates in `structured_value`; the review's
original `answers` map also retains the machine-readable answer data.

Migration updates do not change answer activity timestamps. A database backup was
taken before the local migration. Existing field and attachment namespaces remain
unchanged. PowerSync already selects all intake-field columns; adding the local
`date_config` column does not widen replication access.

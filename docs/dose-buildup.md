# Supplement dose buildup

`DoseBuildup` (`dose_buildups`) is a reusable template definition. Every catalog
`Supplement` belongs to one. The migration and idempotent seeder provide `7 days`
(linear, 25%, seven recorded days), `No buildup` (100%, one day), and
`Dag 1–2 50%, vanaf dag 3 100%` (custom: 50%, 50%, then 100% from the third
recorded day onward).

Manage definitions under **Protocol Templates → Dosisopbouw beheren**. Choose a
definition on a template supplement or in the individual protocol editor. Custom
schedules store consecutive `{day, dose_ratio}` entries starting at day 1 and
ending at 100%. Their duration is derived from the entries. The No buildup
definition always represents the full dose. After creation, only a definition's
name can be edited (including the built-in definitions). Type, starting ratio,
duration, and custom schedule are immutable. Use **Dupliceren als nieuw schema**
to copy a definition, adjust its settings, and save it with a new ID. Select that
new definition on template supplements to use it in future protocol selections.
Referenced definitions cannot be deleted.

Catalog imports reuse existing definition IDs only when their dosing settings
match the export. Name differences are allowed and the destination name is kept.
A conflicting schedule aborts the entire import with an error; it must be given
a new definition ID rather than silently replacing or ignoring the settings.

When saving a protocol supplement, the definition is copied into
`protocol_phase_supplements.dose_buildup`. Later template/definition edits do not
change that copy, even on subsequent saves. Explicitly selecting a different
buildup in the protocol editor replaces the copy, without resetting recorded days.
Automatic full doses use the existing weight calculation. Manual buildup doses
require `full_dose_amount` and `full_dose_unit`; legacy free text remains supported
when buildup is disabled. `dosage` remains the formatted full dose for compatibility.

Progress counts distinct prior dates with a `ProtocolSupplementIntake` belonging
to that exact protocol supplement. Multiple administrations count as one date;
unchecking retains the record and the progress. Calendar gaps, scheduled rest
weeks, and phase overlaps do not restart or independently advance buildup. A new
protocol supplement starts a new buildup.

For linear buildup, step 1 uses the starting ratio, and step `days` reaches 100%.
For custom buildup, step N uses entry N. Later steps stay at 100%. Today's records
do not increase today's percentage. Effective gram doses round upward to multiples
of 2.5 g below 10 g and 5 g at or above 10 g; drops, capsules, and pills round to
whole units with halves rounded upward. This can produce zero whole units for a
small starting amount. Other units retain fractional amounts.

The daily checklist shows the effective dose; the phase overview shows the full
dose and buildup instructions. Future progression depends on recorded intake.
The order list continues to budget the full dose for all scheduled days.

The first recorded dose fixes the amount for the other administrations on that
date. Recorded dosage is immutable on check/uncheck and retries. Offline uploads
retain the amount actually displayed, and local records override refreshed daily
amounts while waiting to sync. Older whole-day checkbox clients record all their
administrations at the same effective dose. For buildup supplements, intake DELETE uploads become unchecks
so recorded dates remain available for progression.

Existing protocols default to no buildup. The migration does not update existing
intakes or historical day snapshots. Newly created history uses recorded doses or
the effective dose for that date. Once frozen, historical dosage is not recalculated.
When an intake is accepted for a frozen day, its preserved dosage is saved as
`recorded_dosage` on the matching snapshot item in the same transaction. Calendar
responses display that recorded amount when present, while the original snapshot
`dosage` remains unchanged. Untouched historical snapshots are not backfilled.

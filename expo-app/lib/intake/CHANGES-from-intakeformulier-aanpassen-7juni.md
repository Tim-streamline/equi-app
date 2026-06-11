# Intake form changes — from "Intakeformulier aanpassen - 7juni.pdf"

Source: `Intakeformulier aanpassen - 7juni.pdf` (10 pages, "Update
04/06/2026"). Builds on the two earlier rounds in
`CHANGES-from-intakeformulier-aanpassen.md` and
`CHANGES-from-intakeformulier-aanpassen-deel-2.md`. Unless noted, changes
target `expo-app/lib/intake/schema.ts`; several touch the renderer
(`components/intake/IntakeField.tsx`), the section logic (`lib/intake/logic.ts`)
and the intake screens (`overview.tsx`, `submit.tsx`).

## Numbering convention
The PDF references questions by number ("Vraag N"). These were mapped to schema
fields primarily **by the quoted question text**, with the number used as a
secondary check. The numbering matches the rendered form position for a typical
respondent (conditional fields counted only when their gate was triggered).
Three independent anchors in the medisch screen confirmed the model: vraag 16 =
hoof-care type, vraag 28 = the em-dash example, vraag 29 = hoefbevangenheid.

## Global / infra
- **Never use an m-dash (—).** Replaced every user-facing em-dash with a hyphen
  or rephrase (medisch attest hints, the voerwissel-toelichting label, the
  huisvesting "ZOMER — Leefruimte" style labels, the dropped medical-history
  placeholder).
- **Hoofdvraag vs. onderzin.** "How to fill in" asides are moved from the main
  label into the smaller `hint` line (e.g. gedrag `typisch-gedrag` /
  `fysieke-signalen`).
- **Incomplete-submit notification.** Tapping VERSTUREN while required answers
  are missing no longer silently disables — it shows a `Alert` ("pushmelding")
  listing exactly which question in which subscreen still needs an answer, and
  the inline "onvolledig" card now spells out the missing question labels per
  section. (`submit.tsx`)
- **New schema/renderer capabilities (logic + IntakeField):**
  - Cross-section `showIf`: a key like `"paard.geslacht"` references a field in
    another section. Used by the gender-only blocks in geschiedenis. `showField`
    and the section aggregates now thread the full answer set.
  - `multi-checked` `showIf` trigger: shows a field only when the referenced
    multi has ≥2 non-"geen" options selected (water "licht toe hoe dit verdeeld
    is" follow-ups).
  - Repeater sub-fields can now be `type: 'radio'` (with `options`) and carry a
    `hint` — used by the medical-events and snack/supplement repeaters.
  - `text` fields can set `lines: N` to render N stacked single-line boxes
    stored as a string array (gedrag "karakter in 3 woorden").
  - New "none" sentinel `Geen merkbare gevolgen meer`.

## Hoofdscherm (overview)
- The top two cards are swapped — the **Protocol-intake** progress card is now
  on top, the disclaimer below it.
- The disclaimer card is restyled to stand out: amber card, "DISCLAIMER" label,
  alert icon, **italic** body text.

## Contactgegevens
- `naam-eigenaar` → "Wat is jouw voor- en achternaam?"
- `tel-eigenaar` (telefoonnummer) **removed**.
- New `socials`: "Hoe kunnen we met je connecten via de socials
  (Instagram/FB/TikTok)?"

## Over je paard
- New `bij-jou-hoelang` ("Hoe lang is jouw paard al bij jou…"), shown when
  `eerste-eigenaar` = nee.
- New `aantal-verhuizingen` (Niet verhuisd / 1 keer / 2-3 / 4-5 / Meer dan 5)
  for everyone, plus `verhuizingen-redenen` (+ "Anders, namelijk") shown when
  the horse moved at least once.
- `conditie-veranderd` sub-text → "Zo ja, hoe? Aangekomen of juist afgevallen?"
- `foto-paard` and `foto-paard-rechts` **removed** (the side-view photos are
  still collected in the Fysiek section).
- New `paard-leuk`: "Vertel iets over wat je paard heel erg leuk vindt om te
  doen." (the previous klacht `leuk` is removed — see below).

## Klacht & hulpvraag
- `acuut` → "Speelt er NU op dit moment iets van het volgende?" (onderzin "Klik
  alles aan wat van toepassing is") with the full grouped checklist (Geen van
  onderstaande / acute situaties / medische behandeling / medicatie / overig /
  Anders). Acute situations stay `criticalIf`. The group headers from the PDF
  are flattened into one list (the multi renderer has no in-list headers).
- `acuut-toelichting` → "Indien je iets hebt aangevinkt, licht dit kort toe."
  (now shows on any non-"geen" pick; optional).
- `thema` → "Welke thema's spelen op dit moment een rol?"
- `da-behandeling` → "Is je paard onder behandeling van een dierenarts?"
- `stressfactoren` → "Zijn er recente veranderingen geweest?"
- `leuk` **removed** (moved to Over je paard).

## (Medische) geschiedenis van je paard
- Section title → "(Medische) geschiedenis van je paard"; new intro onderzin.
- `moeder-metabolisch`: added "Anders, namelijk" (+ conditional text).
- `spenen-inrijden-huis` → "Hoe woonde je paard tussen het spenen en het
  inrijden?"
- The "volledige medische geschiedenis" textarea is **replaced** by a
  `medische-gebeurtenissen` repeater ("+ toevoegen"): datum, diagnose,
  symptomen, dierenarts (radio), and the dierenarts follow-ups (onderzoeken /
  behandelingen / wat hielp wel / niet, each with the "onbekend" onderzin) +
  "Is dit probleem volledig verdwenen?" (radio).
- New gender-only fertility blocks (gated on `paard.geslacht`):
  - **Merrie** and **Hengst** each: "Zijn er ooit vruchtbaarheids- of
    voortplantingsproblemen geweest?" + (on "Ja…") the relevant
    waar/wanneer/dierenarts/diagnose/behandeling/verdwenen/toelichting set, with
    the mare- resp. stallion-specific option lists.
  - **Ruin & hengst**: penis & koker controle (+ bevindingen multi + laatste
    controle).

## Medisch & specialisten
- Intro → "Vragen over de huidige gezondheid van jouw paard, preventieve zorg en
  betrokken professionals."
- `hoefsmid-freq` replaced by `hoefverzorger-type` ("Wat voor type hoefverzorger
  bekapt jouw paard meestal?": Traditionele hoefsmid / Natuurlijke bekapper /
  Hoefspecialist / Anders) + conditional "Anders, namelijk".
- `hoeven-bijz` → "Zijn er bijzonderheden m.b.t. de voeten van je paard?"
- **`ems-attest` removed** (vraag 26 — confirmed with the product owner; note
  the IR and KPU upload fields are intentionally kept).
- After `hoefbevangenheid`, when it is not "nooit", new follow-ups: eerste
  episode, meest recente episode, aantal episodes, röntgenfoto's (+ upload),
  röntgen-/dierenarts-bevindingen, huidige gevolgen (+ "Anders").
- Removed the em-dash from the IR/KPU attest hints.

## Voer & ruwvoer
- Analysis sub-questions written as full sentences ("Wat is het suikergehalte?",
  "…eiwitgehalte?", "…energiegehalte?", "Eventuele overige waarden").
- `hooi-omschrijving`: removed "eerste snede" / "tweede snede".
- `hooi-herkomst`: added "Weet ik niet".
- `hooi-kg-per-dag` → "Hoeveel kilo ruwvoer krijgt jouw paard gemiddeld per
  dag?" with the new weeg-onderzin; `hooi-kg-methode` → "Hoe heb je dit
  bepaald?" (Gewogen in de afgelopen maand / Ooit gewogen, momenteel geschat /
  Geschat).
- `voordroog-verleden` → "Heeft jouw paard ooit ruwvoer gekregen dat in plastic
  verpakt was?" (Nee / Ja, incidenteel… / enkele maanden / meerdere jaren /
  momenteel / Weet ik niet). Old voordroog follow-ups replaced by: type ruwvoer
  (multi + Anders), periode, hoe lang.
- `voer-gewisseld-toelichting` → "Licht de voerwissel toe." (+ onderzin).
- `snacks-aanwezig` → "…buiten het normale rantsoen om?" (+ "Weet ik niet"). The
  snacks multi is replaced by a `snacks-details` repeater (wat / merk / hoeveel
  / hoe vaak [radio] / sinds / waarom [radio]).
- `huidig-extra` → "Welke supplementen krijgt jouw paard momenteel?" with the
  new columns incl. "Heb je verschil gemerkt?" (radio).
- `historie-extra` → "…in de afgelopen 2-5 jaar…" with Merknaam / Exacte
  productnaam / Periode / Hoe lang gegeven / Waarom gegeven / Waarom gestopt.

## Water & uitscheiding
- New intro onderzin.
- `water-type` → "Wat voor water drinkt jouw paard?" (onderzin "Klik alles aan
  wat van toepassing is") with the new option list + "Anders, namelijk" +
  "Licht kort toe hoe dit verdeeld is" (shown when ≥2 selected).
- `water-aanbod` → "Hoe wordt het water aangeboden?" with the new option list +
  "Anders" + "Licht kort toe hoe dit verdeeld is" + "Waar drinkt jouw paard het
  liefst uit?" (both shown when ≥2 selected).
- `water-analyse` is now a radio ("Is er een wateranalyse beschikbaar?":
  Ja/Nee/Weet ik niet) with a conditional upload, placed after the water-aanbod
  block.
- `urine`: "minder"/"meer" → "Minder dan gemiddeld"/"Meer dan gemiddeld".
- `mest-kleur` and `mest-vorm`: added "weet ik niet".

## Gedrag & training
- New `training-overig` after the conditie question ("Als er iets is wat je wil
  toevoegen over de training…", + onderzin).
- `stress-symptomen`: added onderzin "Meerdere antwoorden zijn mogelijk. Wees zo
  volledig mogelijk."
- `typisch-gedrag` / `fysieke-signalen`: moved that same line from the main label
  into the onderzin.
- `karakter`: now renders **3 single-line boxes** (`lines: 3`).

## Mapping notes / best-effort decisions (please verify)
1. **Grouped checklists** (klacht `acuut`; the medical/medication groups) are
   rendered as one flat option list — the multi renderer has no in-list section
   headers. Order is preserved.
2. **Repeater conditionals.** Each row in the medical-events / snack repeaters
   shows all sub-fields; the "alleen indien dierenarts = Ja" / "Anders →
   toelichting" branches are not gated per-row (the repeater has no per-row
   conditional logic). The "onbekend" onderzin guides empty answers.
3. **Fertility / penis-koker gating** uses the new cross-section `showIf`
   (`paard.geslacht`). The mare/stallion follow-ups appear on "Ja, momenteel" /
   "Ja, in het verleden"; "Vermoeden / verhoogd risico" shows only the top
   question.
4. **Medisch vraag 26 = `ems-attest`** was confirmed with the product owner.
5. **Capitalization sweep.** New and reworked option lists use a leading
   capital. A full capitalization pass over *every* legacy option in the schema
   was **not** done (it would force lockstep edits to many flagIf/showIf trigger
   strings and risk regressions); older lowercase options outside the touched
   fields remain. Flag if you want a dedicated follow-up sweep.
6. **Existing answer data.** Several ids/types/options changed (`tel-eigenaar`,
   `leuk`, `foto-paard*`, `hoefsmid-freq`→`hoefverzorger-type`, `snacks-welke`→
   `snacks-details`, water option labels, etc.). `logic.ts` discards stale
   answers that no longer match a field's options/type, so in-progress intakes
   won't crash but those answers read as unanswered.

# Intake form changes — from "Intakeformulier aanpassen - deel 2.pdf"

Source: `Intakeformulier aanpassen - deel 2.pdf` (19 pages, "Update feedback
31/05"). Builds on the first round documented in
`CHANGES-from-intakeformulier-aanpassen.md`. Unless noted, changes target
`expo-app/lib/intake/schema.ts` (the `INTAKE_SCHEMA` data); a few touch the
renderer (`components/intake/IntakeField.tsx`, `FieldLabel.tsx`), the section
logic (`lib/intake/logic.ts`) and the intake screens (`overview.tsx`,
`submit.tsx`, `welcome.tsx`).

## Global / infra
- **"Alle vragen zijn verplicht."** Completion now treats every *visible*
  field as mandatory by default. Fields opt out with `optional: true`
  (genuinely optional uploads, "eventuele toelichting" / "indien nodig" boxes,
  date-of-birth fallback, and a couple of escape-less recognition multis like
  `thema` / `typisch-gedrag` / `fysieke-signalen`). Implemented via
  `isFieldRequired()` in `logic.ts`; `missingRequired()` and the asterisk in
  `FieldLabel` now follow it. The asterisk therefore shows on (almost) every
  question.
- **Section status glyph (hoofdscherm).** Each section row in `overview.tsx`
  now shows a **cross** when not fully filled in and a **check** when complete,
  instead of the per-section themed icon.
- **Water-section crash fixed.** The renderer now coerces persisted values to
  the shape the input expects (`asArray` / `asText` in `IntakeField.tsx`).
  Stale answers saved under a previous field *type* (e.g. `water-type` which
  became a `multi` in round 1) no longer crash on `.filter`/`.includes`.
- **Generalized "none / not-applicable" handling.** `isNoneOption()` now
  recognises an explicit allowlist ("geen", "nee", "nee, nooit", "niet van
  toepassing", "Geen van onderstaande", "Geen andere diersoorten", "Geen
  belangrijke veranderingen", "Geen echte schuilmogelijkheid", "Geen
  opvallende bijzonderheden", "Geen bijzonderheden"). These clear the rest of a
  multi and never count as a real flag. A blanket `startsWith('geen')` was
  deliberately avoided because combinable traits like "Geen actief beheer" and
  "Geen duidelijke klachten, maar…" also start with "Geen".
- **New `Field` props.** `optional`, `placeholder` (text/textarea example
  text), `link` (inline tappable hyperlink in the label). New `Section.icon`
  value `users`.

## Welcome
- Added a prominent note: the intake must be filled in **within 2 months of
  purchase**, otherwise it expires. Reworded the intro (section count is now
  data-driven; dropped the hard "30 minuten" figure).

## Over je paard
- Vraag 11 `eerste-eigenaar`: removed answer "weet ik niet".
- Vraag 12 `conditie`: label → "Hoe zou je de conditie van je paard
  classificeren?"

## Klacht & hulpvraag
- Vraag 5 `acuut`: added answer "Nee".
- Vraag 7 `da-behandeling`: the follow-up was split — "Welke diagnose is
  gesteld?" (`da-diagnose`, shows on "ja, met diagnose") and the new
  "Wat is de focus van het onderzoek?" (`onderzoek-focus`, shows on "ja, in
  onderzoek").
- Relabels: vraag 13 `gedragsveranderingen` → "Zijn er recente veranderingen
  in het gedrag van je paard?"; vraag 14 `allergie` → "Zijn er bekende
  allergieën?"; vraag 16 `stressfactoren` → "Hebben er recent veranderingen
  geweest?"; vraag 17 `leuk` → "Wat vindt je paard heel leuk om te doen?"

## Geschiedenis van je paard
- Vraag 3 `moeder-metabolisch`: swapped the last two options to "geen",
  "onbekend".
- Added 'Weet je het niet? Vul dan "onbekend" in.' to the sub-texts of the
  spenen/inrijden questions that lacked it.
- Vraag 7 `medische-geschiedenis-volledig`: added a 3-line example
  `placeholder` in the answer box.

## Medisch & specialisten
- Screen intro → "Medische voorgeschiedenis, medicatie, onderzoeken en
  behandelaars."
- Vraag 1 `vaccinaties`: added hint "Meerdere antwoorden zijn mogelijk."
- New `ontworming-methode` ("Hoe wordt jouw paard ontwormd?": vaste momenten /
  mestonderzoek / combinatie / weet ik niet), placed before the
  last-deworming question; the old `ontworming-strategie` (+ toelichting) was
  removed (vraag 8 weg).
- New mestonderzoek block: `mestonderzoek-gedaan` ("…in de afgelopen 2 jaar…"),
  conditional `mestonderzoek-laatst`, `mestonderzoek-uitslag` (text, "kan PDF
  zijn maar mag ook tekstueel") and optional `mestonderzoek-uitslag-file`.
- Vraag 7 `ontworming-volgende` → "Staat er een volgende ontworming al op de
  planning?" (ja/nee) + conditional `ontworming-volgende-detail`
  ("Wanneer en met welk middel / werkzame stof?").
- Vraag 10 `tandarts-freq`: removed "Alleen bij klachten / wanneer nodig".
- Vraag 17 `ijzers`: removed answer "anders".
- Vraag 35 (current medication): the big text field + loose
  naam/reden/dosering/sinds fields were replaced by a repeater
  `medicatie-nu-details` ("eerste toevoegen") like the voer/supplement lists.
- Vraag 30 `maag-med-opbouw`: label "of" → "en".

## Voer & ruwvoer
- Vraag 1 `hooi-verpakking` → "Eet je paard ruwvoer uit plastic of uit
  touwtjes?" with options Uit plastic / Uit touwtjes / onverpakt / Een mix /
  Weet ik niet (mix-toelichting follows on "Een mix").
- New vraag 2 analysis block (the old standalone `hooi-analyse` file —
  "huidige vraag 4 vervalt" — was removed): `ruwvoer-geanalyseerd`
  (Ja/Nee/Weet ik niet) + conditional Suiker-/Eiwit-/Energiegehalte / Overige
  waarden + optional `hooi-analyse` upload. Continues with "Hoe omschrijf je
  het ruwvoer?".
- Vraag 5 `hooi-constant`: added "weet ik niet".
- Vraag 8 `hooi-teelt`: label → "Weet je iets over de weide van herkomst /
  teeltomstandigheden?"
- Vraag 10 `hooi-aanbod`: added "Klik alles aan wat relevant is" hint.
- Vraag 11 `hooi-porties`: removed sub-text.
- Vraag 14 `hooi-voerbeurten`: removed.
- Vraag 15 `hooi-eerst-ruwvoer`: label → "Krijgt je paard 's ochtends eerst
  ruwvoer of krachtvoer?"; added answers "mijn paard krijgt geen krachtvoer"
  and "weet ik niet".
- Vraag 16 `hooi-kg-per-dag`: sub-text → "Gok het aantal kilo's NIET, meten =
  weten…".
- Vraag 19 `voordroog-verleden`: added "weet ik niet".
- Vraag 22 `voer-gewisseld`: options → Nee / Ja, in de afgelopen 2 weken /
  maand / 3 maanden / 6 maanden / Ja, langer geleden / Weet ik niet.
- Vraag 23 `huidige-bijvoeding`: repeater columns → Merk / Exacte productnaam /
  Hoeveelheid per voerbeurt / Aantal voerbeurten per dag.
- Vraag 24 `bijvoeding-historie`: → Merk / Exacte productnaam / Wanneer
  ongeveer / Hoe lang gevoerd.
- Vraag 25 `balancer`: converted from text to a repeater with the same columns
  as vraag 23.
- Vraag 26 `mineralen-toegang`: added conditional "Anders, namelijk"
  (`mineralen-toegang-anders`).
- Removed `snacks-detail` ("Graag per product vermelden") under vraag 27.
- Vraag 29 `historie-extra`: → Merk / Exacte productnaam / Wanneer ongeveer /
  Hoe lang gegeven.

## Water & uitscheiding
- The reported "app crasht de hele tijd bij dit scherm" was a stale-data type
  mismatch in the renderer; fixed globally (see infra). No content changes were
  requested for this screen in deel 2.

## Huisvesting & weide (rebuilt)
- Intro → "Waar en hoe woont je paard."
- Zomer & winter each get a **Leefruimte** radio (box → meerdere hectares) and
  a **Stal/box per dag** radio (Nooit … >16 uur), plus an "Eventuele
  toelichting".
- `woonvorm` multi ("Klik alles aan wat relevant is": weidegang, zandpaddock,
  paddock paradise / track system, …, anders) + conditional.
- `bodembedekking`: added "niet van toepassing".
- `foto-stal` ("…(indien van toepassing)") + new `foto-weide`.
- New paddock/buitenruimte questions: toegang tot water, tot ruwvoer, tot
  schuilplek (+ toelichting), `schuilmogelijkheden` multi, droog/comfortabel
  liggen (+ toelichting), `bodem-leefomgeving` multi.
- Weidegang block gated on `komt-op-weide` (Nee, nooit / seizoensgebonden /
  hele jaar / anders): uren zomer & winter, grootte, beheer-multi,
  omschrijving, samenstelling-multi (+ toelichting), toegang tot ruwvoer /
  water / schuil (+ toelichtingen), bodem-multi.
- `mestwater-overgang`: changed from multi to radio with the new
  begin/einde/weet ik niet/n.v.t. options.
- New "Giftige planten in de leefomgeving" block: `giftige-planten` (Nee/Ja/
  Weet ik niet) with conditional soorten / waar-bereikbaar / eten-of-knabbelen
  / toelichting / foto-upload.
- **Sociale huisvesting fields moved out** to the new section below.

## NEW section · Sociale interacties & groepsdynamiek (`sociaal`, nr 8)
The old `groep-*` / `andere-dieren` fields were replaced by the deel-2 set:
`sociaal-leven` (multi), `andere-paarden-leeftijden`, `sociaal-stabiel`
(+ anders), `sociaal-recent` (multi + anders), `sociaal-maatjes` (+ anders),
`sociaal-positief` (multi), `sociaal-spanning` (multi), `sociaal-functioneren`,
`sociaal-positie`, `sociaal-toelichting`, `sociaal-afzonderen`,
`andere-diersoorten` (multi + anders), `andere-diersoorten-contact`.
Sections after this one were renumbered (`gedrag` 9, `fysiek` 10,
`samenvatting` 11).

## Gedrag & training
- Intro → "Wie is je paard. Hoe gaat hij/zij met de wereld om en wat voor
  dingen doen jullie samen?"
- Relabels vraag 1-6 (beweging uit arbeid, discipline(s), hoe vaak per week,
  gemiddelde intensiteit, dingen waar je tegenaan loopt, conditie van je
  paard).
- Sectionhead "Stalondeugden…" → "Stereotiep en opvallend gedrag".
- Vraag 7 `stress-symptomen`: relabel + options weven / ijsberen / luchtzuigen
  / kribbenbijten / "Overmatig schuren, likken, kauwen, flehmen of ander
  repetitief gedrag" / geen.
- Vraag 8 `headshaking`: relabel ("…hoofd schudden of de neus herhaaldelijk
  tegen iets aandrukken?").
- Vraag 9 `agressie` (+ detail): removed.
- Vraag 10 `typisch-gedrag`: relabel + the long voer/gedrag-recognition list.
- New `fysieke-signalen` (vraag 11 — koliek/rug/mestwater signals) and
  `gedrag-signalen` ("Herken je iets van onderstaande signalen?" + anders).
- `soortgenoten`: multi → single-line text ("…in 1 zin?").
- `stress-triggers` → "Is er iets waar je paard stress van krijgt?".
- `karakter`: → "Hoe omschrijf je het karakter van je paard in 3 woorden?"
  (text).

## Fysiek & foto's
- New / reworked observation questions: `lichaamsconditie` (radio), `vacht`
  (multi, replaces `haar`), `huid` (new multi), `bespiering` (radio, new
  options), `benen-hoeven-gevoel` (new multi), `hoeven-kwaliteit` (the big hoof
  list), `slijmvliezen` (radio + "Wisselend, licht kort toe" → conditional),
  new `slijmvliezen-gevoel` (+ conditional), `ademhaling` (radio → multi),
  `algemene-indruk` (new radio). `bouw` was dropped.
- Vraag 8 `fysiek-vrij`: relabel ("Is er iets fysieks dat je opvalt en niet
  hierboven aan bod is gekomen?").
- Vraag 9 photo split into `foto-zijaanzicht-links` and
  `foto-zijaanzicht-rechts`.
- Vraag 15 hoof-instruction hint: removed the em dash.
- "Indien je hulpvraag hoefgerelateerd is": the example photo strip (bron:
  David Landreville) is bundled as `assets/images/intake-hoef-voorbeeld.png`
  and rendered under the sectionhead by `IntakeField.tsx`.

## Versturen
- `samenvatting` intro → "Loop nog een keer door je antwoorden voordat je het
  in stuurt. Na het insturen kan het niet meer worden aangepast." Submit-screen
  tip aligned to the same message.
- `akkoord-data`: the word **privacyverklaring** is now a tappable hyperlink to
  https://www.depaardentherapeut.nl/privacy (via the new `link` field prop).
- Submit button label → **VERSTUREN**.
- Removed the em dash from the overview "tip" block.

## Mapping notes / best-effort decisions (please verify)
1. **"Alle vragen verplicht" scope.** Implemented as "every visible field
   except those marked `optional`". A handful of escape-less recognition
   multis (`thema`, `typisch-gedrag`, `fysieke-signalen`,
   `sociaal-positief`, `sociaal-spanning`, `weide-schuil-soort`) are marked
   optional so the gate can never deadlock. If you want any of these hard
   required, drop their `optional` flag.
2. **Medisch numbering.** Anchored on the unambiguous text references
   ("wat nu vraag 5 is" = last-deworming date) → deel-2 counts the medisch
   screen *excluding* conditional fields and section headers (same convention
   validated in round 1). vraag 17 = `ijzers` follows from that count.
3. **"Wat was de uitslag?" of the mestonderzoek** is offered both as free text
   and an optional file upload ("dit kan PDF zijn maar mag ook tekstueel").
4. **Hoof example photos** are bundled as a single horizontal strip (the PDF
   embedded them as one image). The photo inputs are still the local
   placeholder stub; the strip is shown for reference only.
5. **Cross-section conditionals** (e.g. the hoof-photo block "indien
   hoefgerelateerd") remain always-visible-but-`optional`, since `showIf` only
   references fields within the same section.
6. **Existing answer data.** Many ids/types/option labels changed again
   (e.g. `huisvesting-zomer`/`-winter` textareas → radio groups, `ademhaling`
   radio → multi, `balancer` text → repeater, social `groep-*` → `sociaal-*`).
   `logic.ts` discards stale answers that no longer match a field's
   options/type, and the renderer coerces shapes defensively, so older intakes
   won't crash — but answers under changed ids/types read as unanswered.

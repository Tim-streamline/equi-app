# Intake form changes — from "Intakeformulier aanpassen.pdf"

Source: `Intakeformulier aanpassen.pdf` (13 pages). All changes target
`expo-app/lib/intake/schema.ts` (the `INTAKE_SCHEMA` data) unless noted.
The renderer (`components/intake/IntakeField.tsx`) already supports every
needed field type (`text`, `textarea`, `number`, `date`, `radio`, `multi`,
`photo`, `file`, `repeater`, `sectionhead`) plus `hint`, `unit`, `options`
and conditional display via `showIf`. So conditional "toelichting" fields =
a `text`/`textarea` field with `showIf`; multi-photo = `photo`; PDF upload =
`file`; "nog één toevoegen" = `repeater`.

## Global
- Add an intro/"belangrijk vooraf" disclaimer text (long version) somewhere
  before the form.
- Add a short version at the top of the protocol-intake overview screen:
  "Vul dit formulier zo volledig mogelijk in. We kunnen alleen meedenken op
  basis van wat jij deelt. De adviezen zijn ondersteunend en vervangen geen
  dierenarts. Bij acute of ernstige klachten neem je altijd contact op met je
  dierenarts."
- Tone: use "je paard" instead of impersonal "ze"; make questions full
  sentences ("Is er een wateranalyse aanwezig?" not "water-analyse aanwezig").

### Long disclaimer text
> Belangrijk vooraf:
> Deze app geeft ondersteuning op basis van de informatie die jij invult. Hoe
> vollediger en eerlijker je antwoorden zijn, hoe gerichter het advies kan zijn.
> De adviezen in deze app vervangen geen dierenarts of medische behandeling.
> Bij acute klachten, wonden, extreme benauwdheid, koliekverschijnselen,
> kreupelheid, koorts of duidelijke achteruitgang neem je altijd contact op met
> je dierenarts.
> Door dit formulier in te vullen begrijp je dat het advies gebaseerd is op de
> aangeleverde informatie en dat je zelf verantwoordelijk blijft voor de keuzes
> rondom jouw paard.

## Scherm contactgegevens
- Sub-text "Eerst even hoe ik je kan bereiken, en of je openstaat voor
  veranderingen" → "Laat hieronder weten hoe ik je kan bereiken".
- "Op dit adres stuur ik je protocol en kopie van je antwoorden." → "Op dit
  adres stuur ik je een kopie van je antwoorden en een kopie van je protocol."
- Remove sub-text under vraag 3 (telefoonnummer).
- Remove sub-text under vraag 5.
- Vraag 5 options become only: "Ja, helemaal" / "Ik twijfel nog, leg hieronder
  uit". Remove the "anders" option.

## Scherm over je paard
- Vraag 6 "geschat gewicht" → "Gewicht".
- Remove vraag 10.
- Remove sub-text under vraag 15.
- Vraag 16 → "Foto van je paard (zijaanzicht vanaf links)".
- Add vraag 17 → "Foto van je paard (zijaanzicht vanaf rechts)".

## Scherm klacht & hulpvraag
- Remove top text "ik lees alles persoonlijk".
- Vraag 5 → "Heeft je paard NU klachten?"; remove its sub-text.
- Vraag 5 options: Koorts / Koliek / Staat op reguliere medicatie, toelichting
  hieronder welke / Staat op pijnstillers, toelichting hieronder welke /
  Anders, toelichting hieronder. Add a toelichting field shown when one of the
  3 (medicatie/pijnstillers/anders) is checked.
- Vraag 6 option edits: hoeven→hoefproblemen; remove voeding; ademhaling→
  luchtwegklachten; remove houding en balans; remove spierspanning; gedrag→
  afwijkend gedrag; remove luchtwegen; add Insuline resistentie; add
  Hoefbevangenheid; pees/gewrichten→Pees- of gewrichtsklachten; remove energie.
- Vraag 11 → "Upload een paar foto's van je paard van de afgelopen 2-5 jaar."
  sub: "Dit helpt mij om een goed beeld te krijgen van je paard. Max 10 foto's."
- Vraag 14 → "Heb je ervaring met holistische therapieën?"
- Vraag 15 → "Zijn er recente veranderingen?"
- Vraag 16 sub-text → "Dit helpt me een beeld te krijgen van wat je paard
  gelukkig maakt".

## Scherm geschiedenis van je paard
- Remove top sub-text.
- Swap vraag 1 and vraag 2.
- Vraag 1 → "Hoe heeft je paard de eerste maanden van zijn/haar leven
  doorgebracht?"
- Sub-text under vraag 1 & 2 → 'Weet je het niet? Vul dan "onbekend" in.'
- Vraag 3 → "Had de moeder gezondheidsklachten?"
- Vraag 5 → "Hoe werd je paard gevoerd in die periode?"
- Vraag 6 → "Hoe werd je paard gevoerd in die periode?"
- Vraag 7 → "Wil je de volledige medische geschiedenis uitwerken in
  chronologische volgorde, zover bij jou bekend?"
- Vraag 7 answer box 3x larger (textarea, taller min height).

## Scherm medisch en specialisten
- Vraag 1: after option "anders" add "geef toelichting" → "Anders, geef
  toelichting".
- Add question "Reageert je paard op vaccinaties?" (multi/checkboxes):
  Nee / Ja, een bult/zwelling op de injectieplek / Ja, enkele dagen wat sloom
  of minder fit / Ja, pijnlijke/gevoelige injectieplek / Ja, koorts/verhoging /
  Ja, anders, namelijk (+ toelichting field) / Weet ik niet.
- Vraag 7 option "mix" → "mix, geef toelichting"; add toelichting field when
  filled.
- Add question "Reageert je paard op ontwormingen?":
  Nee / Ja, enkele dagen wat sloom of minder fit / Ja, verandering in mest of
  spijsvertering / Ja, buikgevoeligheid / darmklachten / Ja, verandering in
  gedrag of gevoeligheid / Ja, anders, namelijk (+ toelichting) / Weet ik niet.
- Vraag 8 → "Hoe vaak wordt het gebit van jouw paard gecontroleerd?" (radio):
  Elke 6 maanden of vaker / Ongeveer 1x per jaar / Ongeveer 1x per 1,5–2 jaar /
  Minder vaak dan 1x per 2 jaar / Alleen bij klachten / wanneer nodig /
  Nog nooit / onbekend.
- Vraag 13 → "Door wie wordt het gebit behandeld?": Dierenarts / Gediplomeerd
  paardentandarts / gebitsverzorger / Anders, namelijk: ___.
- Vraag 13 (over de bekapper) → free text field instead of multiple choice.
- Vraag 14: add options hoefbevangenheid and NPA.

## Indien de hulpvraag hoefgerelateerd is (hoef-upload block)
- Upload 3 photos per hoof (12 total): 1 Onderzijde/zool, 2 Achterzijde,
  3 Zijaanzicht. Example photos (clickable) + cleaning/lighting instructions.
- Vraag 19 t/m 21: same answer options as vraag 21.
- KPU option must read "aangetoond uit urine onderzoek" (not "uit dierenarts").
- When "aangetoond" is picked anywhere → ask for urine/bloedonderzoek results
  (file upload, not required).
- Remove vraag 23.
- Vraag 24 → "Herken je één of meerdere van onderstaande signalen bij jouw
  paard?" (multi): Geen van onderstaande / veel gapen / flehmen / slikken /
  Tandenknarsen / Gevoelig bij aansingelen of aanraken van de buik/flanken /
  Geïrriteerd, prikkelbaar of sneller boos/agressief / Onrust, spanning of
  moeilijk ontspannen / Minder eetlust / kieskeurig eten / Langzaam eten of
  stoppen tijdens het eten / Slechter presteren / weerstand bij training of
  rijden / Mestveranderingen (bijv. mestwater, wisselende mest, dunne mest) /
  Vermageren of moeite met op gewicht blijven / Terugkerende koliekachtige
  klachten / Veel liggen / ongemak tonen / Houdings- of gedragsverandering
  rondom voer, training of stalrust / Geen duidelijke klachten, maar ik vermoed
  toch maagongemak.
- Vraag 25 → "Heeft je paard ooit maagmedicatie gehad?" If ja: add fields
  Welke maagmedicatie? / Wanneer was dit precies? / Hoe lang heb je dit
  gegeven? / Heb je op- of afgebouwd? / Zag je effect van de medicatie?
  Omschrijf wat je zag indien ja.
- Vraag 27 → "Heeft jouw paard probiotica gekregen of producten met gist /
  yeast / Yeasacc / Saccharomyces cerevisiae?" (sub: controleer ook de
  ingrediëntenlijst van huidige voeding, balancers en supplementen.) Nee/Ja/
  Weet ik niet. If ja: Welk product/welke producten? / Wanneer kreeg jouw paard
  dit? (Krijgt dit momenteel / In de afgelopen maand / 1–6 maanden geleden /
  6–12 maanden geleden / Meer dan 1 jaar geleden) / Hoe lang kreeg jouw paard
  dit ongeveer? (Korter dan 2 weken / 2–6 weken / 1–3 maanden / 3–6 maanden /
  6–12 maanden / Langer dan 1 jaar / Weet ik niet) / eventuele toelichting
  (dosering, reden van gebruik).
- Vraag 28/29 → "Gebruikt jouw paard momenteel medicatie?" Nee/Ja. If ja:
  "Welke medicatie gebruikt jouw paard momenteel?" (sub: denk aan NSAID's,
  maagmedicatie, corticosteroïden, PPID-medicatie, luchtwegmedicatie,
  antibiotica etc.) + fields Medicatie/productnaam / Reden van gebruik /
  Dosering (indien bekend) / Sinds wanneer.
- "Heeft jouw paard in de afgelopen 2-5 jaar medicatie gekregen?" Nee / Ja,
  incidenteel / Ja, meerdere keren of langdurig / Weet ik niet. + "Welke
  medicatie heeft jouw paard ooit gekregen?" (multi): Antibiotica /
  Ontstekingsremmers / pijnstilling (Bute, Meloxicam, Equioxx) /
  Corticosteroïden / prednison / dexamethason / Maagmedicatie (omeprazol) /
  Ventipulmin / PPID / Cushing medicatie (Prascend) / Sedatie /
  kalmeringsmiddelen / Hormoon- of vruchtbaarheidsmedicatie / Antischimmel /
  antiparasitaire medicatie / Anders, namelijk ___.
  Per-medicatie repeater ("nog één toevoegen", like vraag 22 of voer&ruwvoer):
  Naam medicatie / Vanwege welke klacht/symptoom ingezet? / Wanneer ongeveer
  gegeven (jaartal/maand) / Hoe lang gegeven / Reageerde jouw paard erop?
  (Ja/Nee/Weet ik niet) / Zo ja, hoe?

## Scherm voer & ruwvoer
- Section sub-text → "Wees specifiek in merknamen en hoeveelheden."
- Vraag 1: if "mix", add toelichting field and ask "Geef toelichting over de
  verhoudingen en wanneer wat precies gegeven wordt".
- After vraag 2 add "vink alles aan wat relevant is".
- Vraag 5: remove option "wisselt per leverancier".
- Vraag 6 → "Waar komt het ruwvoer van jouw paard vandaan?": Eigen land /
  eigen productie / Lokaal geproduceerd / Ingekocht binnen eigen land /
  Geïmporteerd / afkomstig uit het buitenland / Wisselende herkomst / onbekend.
- After vraag 6 add: "Is het ruwvoer meestal afkomstig van hetzelfde perceel /
  dezelfde leverancier?" Ja, meestal wel / Nee, wisselt regelmatig / Weet ik
  niet. + "Weet je iets over het land van herkomst / de teeltomstandigheden?"
  (sub: kruidenrijk, bemest, intensief beheerd, natuurgrond, irrigatie,
  onbekend) + "Hoe wordt het hooi van jouw paard geproduceerd / gewonnen?
  (vink alles aan)": 1e snede / 2e snede / 3e snede of later / Kruidenrijk /
  natuurhooi / Productiegras / landbouwgrasland / Bemest land / Onbemest /
  extensief beheerd land / Biologisch / Weet ik niet.
- Vraag 7 → "Hoe wordt het hooi gevoerd?": Los op de grond / Los in bakken /
  Slowfeeders (mazen <3 cm) / Hooinetten (mazen >3cm) / Hooruif (metaal) /
  Anders/combinatie, namelijk: ___.
- "Hoe vaak krijgt jouw paard hooi? (hoeveel porties)": Continu / ad libitum /
  Meerdere keren per dag / 3x per dag / 2x per dag / 1x per dag / Wisselend.
- Vraag 8 → "Heeft jouw paard periodes zonder ruwvoer (inclusief de nachten!)":
  Nee, nooit / Ja, soms / Ja, regelmatig / Weet ik niet.
- Add: "hoeveel uur achter elkaar heeft jouw paard meestal geen toegang tot
  ruwvoer?" Minder dan 1 uur / 1–2 / 2–4 / 4–6 / 6–8 / Meer dan 8 uur /
  Wisselend / moeilijk in te schatten.
- Add: "Wat is de langste periode zonder ruwvoer die jouw paard doorgaans heeft
  (inclusief 's nachts)? ___ uur".
- From vraag 10 onwards stays as-is. Option "tegelijk" → "tegelijkertijd".
- Vraag 13 sub-text → "pak een paar plukjes hooi uit de baal (liefst uit
  verschillende plekken), spreid deze los uit op een neutrale, egale ondergrond
  en maak een duidelijke foto van dichtbij."
- Vraag 14 → "Heeft je paard ooit ruwvoer verpakt in plastic gegeten?
  (voordroog of kuil)".
- Add: "Krijgt jouw paard graszaadhooi?" Nee/Ja/Weet ik niet. If ja: "ongeveer
  hoeveel van het totale ruwvoer bestaat uit graszaadhooi?" <25% / 25–50% /
  50–75% / >75% / 100% / Weet ik niet. + "Is het graszaadhooi gecertificeerd
  geschikt als paardenvoer?" Ja/Nee/Weet ik niet.
- Add stro questions: "Eet jouw paard stro?" (sub: denk aan stro als
  bodembedekking als aan bewust gevoerd stro): Nee, mijn paard eet geen /
  nauwelijks stro / Ja, knabbelt af en toe aan stro als bodembedekking / Ja,
  eet duidelijk mee van de stro-bodembedekking / Ja, krijgt stro bewust
  bijgevoerd als onderdeel van het rantsoen / Ja, zowel stro-bodembedekking als
  bewust bijgevoerd stro / Weet ik niet. If ja: "om welk type stro gaat het?"
  Tarwestro / Gerstestro / Haverstro / Anders, namelijk: ___ / Weet ik niet.
  + "Is het stro biologisch?" Ja/nee. + "Hoeveel stro eet jouw paard ongeveer?
  ___ kg".
- Vraag 14 (later) → "Ben je recent gewisseld van (ruw)voer?"; if ja add
  toelichting "van wat precies".
- Vraag 17 → type answer field like vraag 22; text → "Welke bijvoeding geef je
  allemaal OP DIT MOMENT en in welke hoeveelheden?"
- Vraag 18 → "Welke bijvoeding heeft jouw paard in de afgelopen 2–5 jaar
  gekregen? (exclusief supplementen, die komen later.)" with per-product
  details: Merk + exacte productnaam / Ongeveer wanneer (jaartal/periode) /
  Hoe lang gevoerd (weken/maanden/jaren). (Examples + "graag ook kortdurende
  periodes meenemen".)
- Vraag 19 → "Welke mineralenvoeding / balancer krijgt je paard op dit moment?"
- Vraag 20 → "Heeft je paard verder nog toegang tot andere mineralen?" (sub:
  mineralenbuffet, likstenen etc.)
- Vraag 21 → "Krijgt jouw paard snacks, tussendoortjes of extra's buiten het
  normale voer om? (Denk óók aan kleine dingen.)" Nee/Ja. If ja: "wat krijgt
  jouw paard allemaal?" (multi): Paardensnoepjes / treats / Wortels / Appels /
  Brood / crackers / menselijke etensresten / Likemmers / stal-likproducten /
  "Licki" / boredom breakers / Extra handjes voer / losse brokjes tussendoor /
  Kruiden / planten / takken als snack / Anders, namelijk: ___. + per product:
  Wat precies (merk+productnaam) / Hoe vaak (dagelijks/wekelijks/af en toe) /
  Ongeveer hoeveel.
- Vraag 22 → "Benoem hier alle HUIDIGE supplementen".
- Vraag 23 → "Welke supplementen heeft jouw paard in de afgelopen 2–5 jaar
  gekregen?" (sub list of types) per product: Merk + exacte productnaam /
  Ongeveer wanneer (jaartal/periode) / Hoe lang gegeven.

## Scherm water & uitscheiding
- Remove top sub-text.
- Vraag 1 → "Wat voor water drinkt je paard?"; allow multiple answers.
- Vraag 2 → "Is er een wateranalyse aanwezig?" sub: "Upload PDF" (file upload).
- Vraag 3 → "Hoe wordt water aangeboden? (vink alles aan wat van toepassing
  is)": Automatische drinkbak / Emmer(s) / Grote bak / ton / kuip / Drinkbak in
  stal / Drinkbak buiten / paddock / weide / Natuurlijke waterbron (sloot,
  beek, vijver, etc.) / Anders, namelijk: ___.
- Vraag 4 → "Heeft jouw paard altijd toegang tot water?": Ja, continu / Meestal
  wel / Nee, niet altijd / Weet ik niet.
- After vraag 4 add: "Wordt het water regelmatig ververst / de bakken
  schoongemaakt?": Dagelijks / Meerdere keren per week / Minder vaak / Weet ik
  niet.
- Vraag 5 (now "Hoe omschrijf je de kwaliteit van het water?" vink alles aan):
  remove options "koud" and "lauw".
- Vraag 6 → "Drinkt jouw paard naar jouw idee voldoende?": Ja / Twijfelachtig /
  wisselend / Nee / drinkt opvallend weinig / Drinkt opvallend veel /
  Weet ik niet.
- Vraag 7 → "Hoe is de urine van je paard".
- Vraag 8 → "Wat is de mest frequentie per dag?"; add option "weet ik niet".
- Vraag 9 → "Valt de geur van de mest van jouw paard op?": Nee, ruikt normaal /
  niet opvallend / Ja, zuur / fermentatieachtig / Ja, sterk / scherper ruikend
  dan van andere paarden / Ja, rottingsachtig / onaangenaam / Ja, wisselend /
  Anders, namelijk: ___ / Weet ik niet. Allow multiple answers.
- Vraag 10: allow multiple answers.
- Vraag 12 → "Upload foto's van de mest van je paard" (3 photos): 1 bovenaf van
  de mesthoop / 2 mestbal voorzichtig open close-up / 3 verse mestbal geplet
  close-up. + instructions (verse mest, daglicht, geen stro/zand/modder,
  scherpe foto's).

---

## How the "vraag N" numbers were mapped
The PDF refers to questions by number, but those numbers do **not** line up
1:1 with the schema in every section (the PDF appears to target a slightly
different/newer form revision). Mapping was therefore done by **meaning**,
cross-checked against the numeric anchors that *do* line up. Convention used:
- `paard`, `medisch` — count **excludes** conditional fields and section
  headers (validated: paard vraag 6=gewicht, 15=gezondheid, 16=foto all match).
- `water`, `voer` — count **includes** conditional fields, excludes section
  headers (validated: water 1=water-type, 2=water-analyse, 5=kwaliteit, … and
  voer 22/23 = the two supplement repeaters).

## Flagged / best-effort decisions (please verify)
1. **Over je paard – "Vraag 10 weghalen".** Mapped to `adres-stal` ("Adres van
   de stal"), which the validated numbering puts at position 10. Removed. If a
   different question was meant, restore it.
2. **Geschiedenis – vraag 5 én vraag 6 both "Hoe werd je paard gevoerd in die
   periode?"** Treated as a PDF typo: vraag 5 (`spenen-inrijden-voer`) got that
   label; vraag 6 (`spenen-inrijden-symptomen`) kept its meaning ("Heeft je
   paard in die periode symptomen/ziektes ontwikkeld?") with only a tone fix.
3. **Medisch – the two conflicting "vraag 13"s.** "Door wie wordt het gebit
   behandeld?" was applied to the former `tandarts-methode` field; "bekapper →
   tekstveld" was applied to `hoefsmid-freq` (now a free-text field). Mapped by
   meaning (gebit vs. bekapper), not by number.
4. **Vaccinatie/ontworming/maag/probiotica/medicatie blocks (PDF p.3–8).**
   Added/rewritten in the `medisch` section by meaning (KPU→`kpu-status`,
   maag-signalen→`maag-symptomen`, etc.). `maag-ondersteuning` was removed
   ("vraag 23 verwijderen"). The old free-text `medicatie-nu`/`medicatie-recent`
   were replaced by the structured "Gebruikt jouw paard momenteel medicatie?" /
   "…afgelopen 2-5 jaar…" blocks + per-medicatie repeater.
5. **Hoef-foto upload ("indien de hulpvraag hoefgerelateerd is").** `showIf`
   can only reference fields **within the same section**, and the hulpvraag/
   thema lives in the `klacht` section — so a true cross-section conditional is
   not possible without a logic change. The hoof-photo block was therefore
   added to the `fysiek` section as an **always-visible** group, clearly
   labelled "Indien je hulpvraag hoefgerelateerd is", with one photo field per
   hoof (each hint lists the 3 required angles). The "12 photos / 3 per hoof"
   and "clickable example photos" are conveyed via copy; the photo input is
   still the existing local placeholder stub (no real upload yet) and does not
   render example images or enforce a per-hoof count.
6. **Conditional "toelichting" fields, PDF uploads, "voeg nog één toe"
   constructions** are implemented with existing field types (`text`/`textarea`
   + `showIf`, `file`, `repeater`). No new field type was needed.
7. **Tone ("ze" → "je paard") and "volledige zinnen".** Applied in the sections
   the PDF addresses (contact, paard, klacht, geschiedenis, medisch, voer,
   water). The untouched sections (`huisvesting`, `gedrag`, `fysiek`,
   `samenvatting`) still contain some "ze"/"haar" phrasing — a full tone pass
   there is still open.
8. **Renderer change.** Added an optional `tall` flag on textarea fields (used
   for the medical-history box, "3x zo groot") in `components/intake/
   IntakeField.tsx` + `schema.ts` `Field` type.
9. **Existing answer data.** Several option labels and a few field ids changed
   (e.g. `krachtvoer-detail`→`huidige-bijvoeding` repeater, `water-type`
   radio→multi). `logic.ts` already discards stale answers that no longer match
   a field's options/type, so previously-saved intakes won't crash — but
   answers under changed ids/types will read as unanswered.

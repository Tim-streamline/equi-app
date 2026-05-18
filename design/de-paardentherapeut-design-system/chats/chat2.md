# Equinova App Design

_Started 2026-05-16 15:21 UTC_

---

## User

Kan je me helpen met een app bouwen die hierop lijkt?  Voor equinova

## Assistant

_[tool: questions_v2]_

Wat een leuk project! Voordat ik begin met ontwerpen, wil ik graag wat dingen weten zodat het echt past bij Equinova.

## User

Questions answered:
- context: Equinova is tweeledige app. Enerzijds zodat een paardenhouder elke dag kan zien wat ze moet doen voor haar zieke paard. Ze vult super makkelijk een volledig online intake in en upload foto's en alle benodigde informatie. Ze krijgt daarna een persoonlijk plan om te werken aan langdurige gezondheid. Ze kan in de kalender dagelijks zien welke kruiden ze moet geven en beantwoordt regelmatig vragen over de voortgang en upload foto’s/video’s indien nodig Shelley ziet alles en stuurt bij als het nodig is.

Anderzijds voor dagelijks gebruik met:

- “Affirmaties” (optie om ook uit te zetten), met bijzonderheden in het seizoen, kruid X groeit nu, ga lekker plukken want het is gezond voor Y.
- Een barcode scanner of andere manier om voer en supplementen te beoordelen en zoals de INCI beauty app te zijn wat er allemaal in zit waarom het goed/slecht is.
- Je kan invullen wat voor to-do’s je hebt zodat je reminders krijgt (mineralen buffet verversen, nieuw voer bestellen, voeten bekappen, mestonderzoek enz enz)
- Community

**Pad 1 — Basis (€19/maand)**

Een slimme stal-app én toegang tot Shelley's kenniswereld. Geen protocol, geen persoonlijke begeleiding. Wel:

- Barcode scanner: scan voer of supplement en zie direct of het goed of slecht is voor je paard op basis van Shelley's methodiek
- Contentbibliotheek: een groeiende verzameling video's van Shelley over specifieke onderwerpen. Leverondersteuning, mest beoordelen, waarom bewegingsvrijheid cruciaal is, hoe je stress herkent bij je paard, welke kruiden wanneer. Shelley maakt content wanneer ze inspiratie heeft. Gebruikers kijken wanneer ze willen. De bibliotheek wordt waardevoller naarmate ze groeit.
- Seizoensgebonden kruidentips (bijv. kruid X groeit nu, ga het plukken want het is gezond voor Y)
- Affirmaties (uitschakelbaar)
- Eigen to do's instellen met reminders (mineralen buffet verversen, hoeven bekappen, mestonderzoek, nieuw voer bestellen etc.)
- Community waar Shelley incidenteel aanwezig is en reageert als ze iets interessants ziet. Geen verplichte sessies. Gewoon haar aanwezigheid.

**Pad 2 — Persoonlijk protocol (€19/maand + €97 eenmalig)**

Alles van pad 1 plus een volledig persoonlijk herstelprotocol van Shelley, geïntegreerd in de app. Op basis van een uitgebreide intake en foto's.

Het protocol bestaat uit:

1. Een mooi opgemaakt rapport met analyse en waarschijnlijkheidsconclusie: wat er aan de hand is, waarom, en wat de aanpak wordt
2. Volledig advies over voeding (wat, hoeveel, welke producten), management (huisvesting, beweging, stress) en training
3. Alle to do's die anders moeten
4. Herstelprotocol in een apart tabblad: alle stappen overzichtelijk onder elkaar
5. Kalenderfunctie: klik een dag aan en zie exact wat je die dag moet doen en geven
6. Producten gelinkt naar [dtails.nl](http://dtails.nl) met kortingscode paardentherapeut-5
7. Alles in huisstijl, gericht op gebruikersgemak

Shelley monitort de voortgang via haar dashboard. Na 6-8 weken volgt een evaluatie en een vervolgprotocol.

**Wat pad 2 NIET is:**

- Geen directe chatfunctie met Shelley
- Geen one-on-one sessies
- Bijsturing gaat via de app, niet via persoonlijk contact

---

## Voor wie?

**Gebruiker 1: De onbewuste veroorzaker**

De paardenhouder die haar paard ziek houdt door verkeerd management en voeding zonder dat ze het weet. Ze bestrijdt al jaren de symptomen. Dierenarts, supplementen, behandelingen. Niets werkt structureel. Ze gelooft in een natuurlijke aanpak maar weet niet hoe. Ze heeft behoefte aan persoonlijke begeleiding, geen cursus om zelf uit te pluizen.

**Gebruiker 2: De gemotiveerde maar zoekende eigenaar**

De paardenhouder die al veel goed doet. Goede voeding, natuurlijke aanpak, bewust bezig. Maar ze mist de juiste volgorde. Ze geeft kruiden terwijl de darmen nog niet klaar zijn voor de volgende stap. Ze doet het juiste op het verkeerde moment en begrijpt niet waarom het niet aanslaat.

**De ideale gebruiker in één zin:**

De paardenhouder die al jaren geld uitgeeft aan dierenartsen, supplementen en behandelingen voor een paard dat maar niet beter wordt, die gelooft in een natuurlijke aanpak maar niet weet hoe, behoefte heeft aan persoonlijke begeleiding en niet doorheeft dat ze ofwel zelf het probleem veroorzaakt door verkeerd management en voeding, ofwel de juiste stap mist door in de verkeerde volgorde te werken.
- design_system: Nee, verzin een passende stijl
- vibe: Natuurlijk & aards (groen, warm, organisch)
- screens: Home (zoals in screenshot), Protocol detail, Scanner (voer beoordelen), Bibliotheek, Community, Paard profiel (Nova)
- horse_count: Decide for me
- scanner_purpose: een foto van de analyse en ingrdientenlijst uploaden of maken en dan op basis van mijn voer beoordelen GPT wordt gekeken of het passend is. Zoals de INCI beauty app te zijn wat er allemaal in zit waarom het goed/slecht is.
- protocol_purpose: Herstelprotocol om gezondheidsklachten aan te pakken.

- library_purpose: Audio, video en tekst over allerlei topics gerelateerd aan paardengezondheid.
- community: Forum / posts
- variations: 2 verschillende visuele richtingen
- tweaks: Kleuren / palette, Lettertype, Hoekige vs ronde cards, Density (compact vs ruim)
- tone: Warm & persoonlijk (jij/je, voornamen)
- platform: Beide — toon in iOS frame

## Assistant

## Assistant

_[tool: update_todos]_

_[tool: copy_starter_component]_

_[tool: copy_starter_component]_

_[tool: copy_starter_component]_

Helder, dank voor de uitgebreide context! De dummy schermen helpen enorm. Ik ga aan de slag.

Aanpak:
- **iOS frame prototype**, klikbaar tussen alle 6 schermen
- **2 visuele richtingen naast elkaar** op een canvas: "Weide" (zacht, serif, ruimer) en "Stal" (warm, modern sans, denser)
- **Tweaks panel** voor kleur, font, card-rondingen en density
- Schermen: Home · Analyse/Protocol/Kalender · Scanner · Bibliotheek · Community · Nova profiel

_[tool: list_files]_

_[tool: read_file]_

_[tool: str_replace_edit]_

_[tool: show_to_user]_

## Assistant

De brand mark zegt nu **EQUINOVA** in alle drie de tegels (canvas, mint, blue-green).

## User

Het protocol tabblad wil ik graag met subtabbladen meer zoals deze bijlages

## Assistant

_[tool: list_files]_

_[tool: list_files]_

_[tool: read_file]_

_[tool: read_file]_

_[tool: read_file]_

_[tool: read_file]_

_[tool: read_file]_

_[tool: read_file]_

_[tool: str_replace_edit]_

_[tool: str_replace_edit]_

Helder. Ik bouw het Protocol-scherm om naar één scherm met drie subtabs: **Analyse / Protocol / Kalender**, met daarin het fasen-systeem en de mei-kalender zoals in jouw voorbeelden.

_[tool: read_file]_

_[tool: str_replace_edit]_

Nu vervang ik het Protocol-scherm met de nieuwe getabde versie:

_[tool: str_replace_edit]_

_[tool: str_replace_edit]_

_[tool: show_html]_

_[tool: done]_

_[tool: fork_verifier_agent]_

Protocol-tabblad heeft nu drie subtabs:
- **Analyse** — mint "waarschijnlijkste oorzaak"-callout + Advies-rijen (Voeding · Management · Training)
- **Protocol** — Voorbereiding (klaar) → Fase 1 Darmen (actief, uitgeklapt met items) → Fase 2 / Fase 3 (vanaf wk 5 / 7)
- **Kalender** — Mei 2026 grid met groene 'gedane' dagen, donkergroen voor vandaag, daaronder de "Vandaag — 10 mei" lijst

Het scherm vind je via tab **Protocol** in de tabbar, of via de jumper "07 · Protocol".Het Protocol-tabblad is omgebouwd naar drie subtabs. Laat me weten of dit qua structuur klopt — daarna kan ik bv. fase-cards uitvouwbaar maken, of dezelfde subtab-structuur toepassen op andere schermen.

## User

Verifier agent check completed

## Assistant

Top — verifier bevestigt dat alle drie de subtabs correct werken en eruitzien als bedoeld. Laat maar weten wat de volgende stap is!

## User

Het hoofdscherm van het tablad "protocol" moet zijn het subtabblad "protocol".
Daarna het scherm met de kalender en dan pas de analyse.

De lay-out van de kalender mag een stuk kleiner en compacter. 
En ik wil dat mensen per dag kunnen afvinken wat ze hebben gegeven aan producten.

## Assistant

_[tool: grep]_

_[tool: str_replace_edit]_

_[tool: str_replace_edit]_

Nu het bijbehorende CSS — compactere kalender + day-checks:

_[tool: str_replace_edit]_

_[tool: str_replace_edit]_

_[tool: done]_

Drie aanpassingen:

1. **Volgorde subtabs** is nu Protocol → Kalender → Analyse, en Protocol opent als default
2. **Kalender is compacter** — kleinere cellen (max 300px breed), kleinere fonts, dunnere koptekst met maand-navigatie en kleine legenda
3. **Per dag afvinken** — tik op een dag in de kalender om die te selecteren, daaronder verschijnen de items voor die dag als afvinkbare regels. Cellen kleuren mee: groen = alles gedaan, mint = deels gedaan, donkergroen = vandaag/geselecteerd

_[tool: fork_verifier_agent]_

Klaar — Protocol opent nu als default, Kalender is compacter en je kunt per dag afvinken. Verifier checkt het door.

## User

Verifier agent check completed

## Assistant

Top — alles werkt zoals bedoeld. Laat maar weten wat de volgende stap is!


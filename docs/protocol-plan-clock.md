# Planklok van het herstelplan

Welke kruiden er op Vandaag staan, welke fases lopen en welke week de klant ziet, rekent één onderdeel uit: `backend/app/Support/ProtocolClock.php`. Dat gebeurt per fase en per kruid of supplement, in plandagen. De volgende onderdelen gebruiken deze klok:

- het dashboard: Vandaag, de kalender, het weeklabel en de fases;
- de dag-historie;
- de controle bij afvinken;
- de fase-herinneringen en browserherinneringen;
- de einddatum en korting van de evaluatie;
- de weekupdate;
- `ProtocolTiming`.

## Regels

1. **Plandagen.** Dagen waarop het plan niet liep, tellen niet mee: gepauzeerd, afgerond, gearchiveerd of terug naar concept. Dat staat in `protocols.paused_periods` en `closed_periods`. Een pauze schuift dus alle fases op.
   - De dag van pauzeren en de dag van hervatten zijn wel plandagen. Wie 's avonds pauzeert en de volgende ochtend hervat, slaat niets over.
   - `ProtocolCompletion::recordPause` houdt dit bij bij elke statuswijziging.
2. **Fases die tegelijk lopen.** Een fase die volgens de admin begint terwijl de vorige nog loopt ("start na X weken" en X korter dan de vorige fase), begint in haar geplande week. Inhalen in een andere fase verandert daar niets aan.
3. **Opvolgers.** Een fase die volgens de admin pas begint als de vorige fase is afgelopen ("na de vorige fase", of "start na X weken" en X minstens zo lang als de vorige fase), begint pas als die vorige fase helemaal klaar is, inhalen inbegrepen.
   - Voorbeeld: Darmrevalidatie na Darm reset.
4. **Tegoed per kruid.** Elke fase loopt haar weken van 7 plandagen. Na elke geplande week krijgt een kruid tegoed:
   - is het op minstens zijn **Minimum per week** (`min_aantal_per_week`) aantal dagen gegeven: **7 dagen**;
   - anders: het aantal dagen dat het is gegeven.

   Een dag telt als gegeven als er minstens één gift van dat kruid is afgevinkt, ook bij kruiden met meer giften per dag. Een week met te weinig giften schuift niets op.
5. **Klaar.** Een kruid is klaar zodra zijn tegoed het **Maximum in fase** (`max_aantal_in_fase`) bereikt. Daarna staat het niet meer op Vandaag. Het doel is nooit hoger dan de geplande weken × 7. Is er geen maximum, dan geldt dat aantal.
6. **Inhalen aan het eind van een fase.** Na de laatste week van een fase:
   - blijven alleen de kruiden van die fase op Vandaag die nog tekort hebben;
   - telt elke dag dat zo'n kruid wordt gegeven als 1;
   - is de fase klaar zodra al haar kruiden klaar zijn.

   Andere fases lopen ondertussen gewoon door.
7. **Vandaag verandert vandaag niet.** Wat er op een dag op het schema staat, hangt alleen af van de dagen ervoor. Een vinkje van vandaag telt in de app wel meteen mee in "Geef het nog x dagen".
8. **De toekomst is een verwachting.** Voor komende dagen gaat de klok ervan uit dat elke dag een plandag is waarop alles wordt gegeven. Daarmee worden de start- en einddatums van fases berekend, en dus ook de meldingen, de kalenderlijnen en de einddatum van de evaluatie.
9. **De admin-preview** rekent met de ideale situatie: alles gegeven, geen pauzes.

## Wat de klant ziet

- **Weeklabel.** Het label volgt de fase die het verst is, en komt nooit hoger dan de laatste week van die fase. Het tweede getal ("van 12") blijft het geplande totaal.
  - "· verlengd" staat er alleen als alle lopende fases aan het inhalen zijn, bijvoorbeeld "Week 12 van 12 · verlengd".
- **Zin boven de lijst op Vandaag**, zodra een kruid inhaalt: "Heemstwortel is in deze fase minder vaak gegeven. Geef het nog 3 dagen."
  - Wachten alle lopende fases op het inhalen, dan volgt ", daarna start week 9." of ", daarna is je herstelplan rond."
  - Het getal telt meteen af als er wordt afgevinkt.
  - Het dashboard stuurt hiervoor `protocol.extension` mee: `extended`, `nextWeek`, en per kruid `id`, `name` en `remaining`.
- **Weekupdate.** Die wordt na elke 7 plandagen gevraagd, ook tijdens het inhalen.
  - In `protocol_weekly_updates.week_number` staat daarom het nummer van die 7-dagenperiode.
  - Zonder pauzes en inhalen is dat gelijk aan het weeknummer.

## Voorbeeld: Darm protocol

| Fase | Gepland | Start |
|---|---|---|
| Maagondersteuning | week 1–8 | begin |
| Darm reset | week 3–4 | tegelijk met Maagondersteuning |
| Darmrevalidatie | week 5–10 | opvolger van Darm reset |
| Mineralen aanvullen | week 5–12 | tegelijk met Darmrevalidatie |

Haalt Silsterk (Darm reset) na week 4 nog 5 dagen in, dan:

- begint Darmrevalidatie 5 dagen later;
- begint Mineralen gewoon in week 5;
- staat bovenaan "Week 5 van 12", zonder "verlengd", omdat Maagondersteuning en Mineralen in week 5 lopen.

## Migratie

`2026_11_27_000001_add_plan_clock_to_protocols` voegt `paused_periods` en `minimum_gifts_from` toe aan `protocols`.

- **Pauzes met terugwerkende kracht.** Eerdere pauzes worden uit de auditlog gehaald: statuswijzigingen van en naar `active`. Een plan dat eerder gepauzeerd is geweest, staat na de migratie dus weer waar het werd gepauzeerd, plus de plandagen sinds het hervatten. Een plan dat nu niet actief is en geen vastgelegd moment van stoppen heeft, telt als gestopt op de dag van de migratie.
- **Lopende plannen.** `minimum_gifts_from` wordt voor gestarte plannen op de dag van de migratie gezet. Dagen daarvoor tellen als gegeven, zodat lopende plannen niet verschuiven door giften die toen niet zijn afgevinkt. Nieuwe plannen laten het veld leeg, dus voor hen geldt de regel vanaf dag één.

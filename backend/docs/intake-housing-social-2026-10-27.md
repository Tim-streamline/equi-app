# Huisvesting en sociale interacties (27 oktober 2026)

Deze uitbreiding gebruikt `intake_sections` en `intake_fields`; antwoorden en bestanden blijven in de bestaande tabellen. Geen nieuwe vragen in de interfacecode. De database is leidend; de bestaande app-bootstrap bevat dezelfde standaardvragen voor offline opstarten.

## Vragen en logica

- Bestaande seizoensbeschrijvingen, groepsleven, sociale positie en overige relevante vragen blijven bestaan.
- `leefruimte-paarden` telt het eigen paard mee; `leefruimte-oppervlakte` vraagt de huidige totale leefruimte in m².
- Vervolgvragen over ruwvoerplekken en gedwongen opstaan verschijnen bij gedeelde huisvesting.
- De vier vragen `wegjagen-*` staan één keer in Sociale interacties. Meldingen over groepsgedrag, eten en rusten kunnen deze activeren.
- Individuele huisvesting zonder zicht/neuscontact verbergt deze groepsvragen.
- Schuilinrichting verschijnt bij een schuilstal/overkapping, onvoldoende veilige liggelegenheid of verstoorde rust.
- `leefomgeving-overzicht` is een optionele foto met maximum 1, met de bestaande grote preview/verwijderknop en een grotere afbeelding in PDF.
- Oude algemene seizoensantwoorden blijven herkenbaar; ze worden niet omgezet naar een specifieke oorzaak zoals regen of hitte. Opgeslagen waarden worden niet herschreven.

## Beheer

Alle teksten, opties, volgorde, optionele/verplichte status en voorwaarden zijn te beheren onder Intakevragen. Het algemene voorwaardenformulier ondersteunt groepen en uitsluitingen. De bestaande geavanceerde JSON-invoer blijft beschikbaar.

Bestaande `show_if`-regels blijven geldig. Toegevoegd:

- `$all`: alle onderliggende regels gelden.
- `$any`: minstens één regel geldt.
- `$not`: de regel geldt niet.
- `{ "huisvesting.leefruimte-paarden": { "gte": 2 } }`: numerieke ondergrens.

Dezelfde evaluatie geldt in de app, voortgangscontrole, adminreview en PDF. Bij verbergen worden antwoorden niet gewist. In admin en PDF staan ze apart als eerder ingevuld; signaleringen, protocollogica en verplichte-veldcontroles gebruiken alleen zichtbare vragen. Archiveren bewaart de definitie en antwoorden.

## Uitrollen

De gerichte migratie `2026_10_27_000002_expand_housing_and_social_intake` leest het vaste configuratiebestand `housing-social-2026-10-27.json`. Gebruik hiervoor geen volledige reseed: die kan andere CMS-aanpassingen overschrijven. De migratie voegt definities toe en wijzigt alleen de aangewezen instellingen; geen antwoord- of uploadtabellen worden gewijzigd.

Rol backend en de bijgewerkte web/native client gezamenlijk uit: oudere clients kennen de nieuwe voorwaardegroepen nog niet.

Oudere app-versies verbergen vragen met `$all`, `$any`, `$not` of `gte`. Zolang `INTAKE_REQUIRE_GROUPED_CONDITIONS=false` (standaard) blokkeren deze vragen het insturen daarom niet op de server; de huidige app blijft ze wel verplicht stellen. Zet de waarde op `true` zodra alle eigenaren de update hebben.

## Hoeveelheid bijvoeding

`huidige-bijvoeding` en `bijvoeding-historie` vragen de hoeveelheid als getal met per product een keuze gram of kilo (`eenheid`, gekoppeld via `unitField`). Migratie `2026_10_27_000005_add_feed_portion_unit_choice` past bestaande definities aan zonder antwoorden te wijzigen. Oude tekstantwoorden zoals "2 scheppen" blijven geldig en zichtbaar; een nieuw getal zonder eenheid telt in de app als onvolledig. Lokale migratie is uitgevoerd; deze taak publiceert niets naar staging/productie.

## Validatie

- 18 backendtests: intake-uitbreiding, adminbewerking, bestaande intakeviewer en PDF-HTML.
- 23 gerichte apptests: nieuwe vertakkingen, verborgen antwoorden, bestaande landbouwvragen, uploads, opslag en gesynchroniseerde configuratie.
- TypeScriptcontrole en admin/webbuild geslaagd.
- De brede app-testset heeft daarnaast 10 reeds bestaande fouten rond de gewichtsdatum in `intake-completion.test.mjs`; dezelfde fouten zijn gereproduceerd met de ongewijzigde HEAD-definities. Deze uitbreiding wijzigt die datumvraag niet.

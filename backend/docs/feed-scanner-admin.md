# Voerscanner: eerste beheerfase

De admin gebruikt bestaande producten en ingrediënten. De gebruiker beheert drie onderdelen:

- `/admin/feed-scanner/ingredients`: kenniskaarten met categorie, basisbeoordeling, uitleg, uitzonderingen, exacte synoniemen/verwante termen, taal, bibliotheeklink en interne notities.
- `/admin/feed-scanner/rules`: aparte conceptbeleidsversies voor standaardvoer en balancers; voedingskundige uitlegkaarten met eigen publicatiestatus. Geen puntenweging of scoreberekening.
- `/admin/feed-scanner/products`: categorie, merk/naam/barcode, originele etiketregel, geordende ingrediënten, vastgelegde koppelingen, analytische waarden met eenheid/analysebasis, bron, dosering, interne notities, etiketbestanden, controle en historie.

`Te beoordelen` is een filter op dezelfde producten. Het dashboard telt diezelfde werkvoorraad. Bestaande catalogusproducten zonder nieuwe controle tellen mee. Sorteren op scanfrequentie gebruikt bestaande `scan_results`.

## Bewust eenvoudige opslag

Ten opzichte van het volledige ontwerp zijn alleen zeven nieuwe tabellen nodig voor deze fase:

| Tabel | Functie |
|---|---|
| `ingredient_revisions` | Onveranderlijke inhoudsversies van ingrediëntenkennis |
| `ingredient_terms` | Termen per revisie, taal en type; alleen exacte synoniemen zijn bedoelde automatische matches |
| `feed_assessment_profiles` | Standaardvoer en balancer |
| `feed_rule_versions` | Conceptbeleid, beleidsgrenzen, uitzonderingen en snapshot van gepubliceerde kennis |
| `feed_knowledge_cards` | Uitlegkaarten; wijzigingen ook in de bestaande auditlog |
| `product_versions` | Samenstelling, analytische waarden, bron, review en auteurschap per productversie |
| `feed_label_attachments` | Privé opgeslagen bewijsbestanden per productversie |

Samenstellingsregels en analytische waarden staan als gevalideerde arrays in de onveranderlijke productversie. Er zijn geen losse dubbele voedingswaardetabellen of generieke workflow-engine toegevoegd. De arrays bewaren stabiele veldcodes, originele waarden/bron, eenheid, analysebasis en ingrediënt-/revisiereferenties. Dit vormt later gestructureerde invoer voor AI-koppelingen en de beoordelingsmotor. De admin toont formulieren, geen JSON-editor.

Tabellen voor AI-inzendingen, automatische beoordelingen en scorecorrecties worden pas toegevoegd bij het aansluiten van die functies. De bestaande `scan_results.horse_id` blijft beschikbaar voor een latere persoonlijke beoordeling; deze fase verandert geen paardenprofielen of scanscores.

## Werking en controle

Een wijziging van een ingrediënt of product maakt steeds een nieuwe conceptversie. De huidige gepubliceerde productidentiteit/inhoud blijft staan totdat een beheerder de nieuwe versie goedkeurt. Een gepubliceerde versie wordt niet overschreven. De bestaande product-ingrediëntpivot is bij nieuwe producten uitsluitend een projectie van de goedgekeurde, bevestigde koppelingen.

Beheerder/eigenaar mag publiceren, goedkeuren en archiveren. Content editors mogen concepten opslaan en etiketten toevoegen, maar niet goedkeuren. Verouderde formulieren kunnen een nieuwere versie niet stil overschrijven. Dubbele exacte synoniemen voor verschillende actieve ingrediënten blokkeren publicatie; verwante termen blijven mogelijk.

De oude adminroutes blijven bruikbaar voor legacyrecords en maken bij wijzigingen ook een versie. Ze mogen nieuwe beheerde records niet buiten het publicatieproces wijzigen. Historiehoudende records worden gearchiveerd in plaats van verwijderd.

Etiketten: JPG, PNG, WebP of PDF, maximaal 15 MB per bestand; meerdere bestanden toegestaan. Opslag op de private `local` disk, download uitsluitend via bevoegde adminroutes. Een volgend concept behoudt de bestanden van de voorgaande versie zonder ze te veranderen. Uploaden kan alleen op het nieuwste nog niet goedgekeurde concept. Er wordt nog geen AI-verzoek verzonden.

Voedingswaarden met onbekende analysebasis blijven onbekend. Deze fase voert geen omrekening of beoordeling uit. Een bevestigde seleniumtoevoeging of specifieke chemische vorm vereist een bronvermelding. Een analytische seleniumtotaalwaarde blijft daarvan gescheiden.

## Migratie en deployment

`2026_10_22_100000_create_feed_catalog_management.php` voegt de tabellen en catalogusstatus/notities/publicatiepointer aan bestaande producten en ingrediënten toe. Bestaande IDs, categorieën, vrije hoeveelheden, ingrediëntenvolgorde en scans blijven behouden. De backfill maakt uitsluitend legacyversies en is opnieuw uitvoerbaar zonder duplicaten; er vindt geen automatische goedkeuring plaats.

De afgesproken waarden staan in de bevroren migratiemanifest `database/data/feed-policy-2026-10-22.json`. De migratie initialiseert twee afzonderlijke conceptprofielen; daarna beheert de admin die waarden. Er worden geen willekeurige strafpunten, basiscijfers of eindscoregrenzen opgeslagen.

Deploymentvolgorde: back-up, migratie, backend/adminbuild activeren, vervolgens nieuwe PowerSync-regels laden. De aangepaste regels gebruiken expliciete publieke kolommen en sluiten concepten/gearchiveerde records uit. Interne notities, revisies, etiketten en conceptbeleid worden niet algemeen gesynchroniseerd. De nieuwe server-tabellen zijn niet aan de client-uploadallowlist toegevoegd.

De migratie weigert een destructieve `down()`: terugschakelen gebeurt met gecontroleerde back-up/compatibiliteit, zonder nieuwe catalogushistorie te wissen. Voor de lokale uitvoering is een private databaseback-up gemaakt onder `storage/app/private/equiapp-before-feed-catalog-20261022.dump`.

## Volgende fase

Kalibratie bepaalt essentiële gegevens en puntenweging per profiel. Daarna volgen de deterministische evaluator, juiste analyseconversies, bewijsafhankelijke seleniumplafonds, versiegebonden berekeningen, verschilweergave en gemotiveerde scorecorrecties. Bij het aansluiten van de scanner worden nieuwe berekende scores servergestuurd; bestaande door de client schrijfbare legacyresultaten gelden niet als door die motor gevalideerd.

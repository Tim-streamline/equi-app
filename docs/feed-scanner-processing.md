# Voerscanner: foto’s verwerken

De app begint met één foto van de samenstelling. De server leest die lokaal met Tesseract en vergelijkt deze met gecontroleerde productversies. Voor deze stap worden geen betaalde AI-aanroepen gedaan. Bij een eenduidige match bevestigt de gebruiker het product en wordt de bestaande beoordeling geopend (of met de actieve regels opnieuw berekend, zonder AI).

Bij onbekende, gewijzigde, onvolledige of onzekere samenstelling vraagt de app ook de **analyse en voorkant**. Pas als alle drie de foto’s zijn ontvangen en de gebruiker verstuurt, begint de bestaande AI-etiketverwerking. Deze gegevens komen als afzonderlijk product ter beoordeling in de bestaande admin. Foto’s blijven op de private `local`-disk. De gebruiker kan een inzending vanuit Scanhistorie hervatten, ook als er al foto’s zijn ontvangen.

## Producten, inzendingen en correcties

Een product staat voor één recept. Een gewijzigde samenstelling wordt een **apart product**, eventueel gekoppeld aan het oorspronkelijke product via `related_product_id`. Een overeenkomst in merk, naam of barcode voegt recepten nooit samen. Verschillende recepten mogen dezelfde barcode hebben. Het oorspronkelijke product, zijn gepubliceerde gegevens en bestaande scans blijven bestaan.

Een herkend, ongewijzigd recept gebruikt het bestaande product en de bestaande beoordeling met de actieve regels. Een nieuwe scan voegt alleen een inzending met eigen etiketfoto’s en een persoonlijk beoordelingssnapshot toe. Er wordt geen productcorrectie aangemaakt. Gedeelde ingrediëntenlijsten van verschillende producten blijven onzeker; de gebruiker bevestigt ook merk, naam en voedingswaarden.

Bij onbekende of afwijkende etiketten wordt eerst een afzonderlijk conceptproduct zonder correctiegeschiedenis klaargezet. De foto’s behoren aan de inzending. De eerste productrevisie ontstaat pas wanneer de etiketten zijn uitgelezen of een beheerder de gegevens invult. Ontbreekt de AI-sleutel, dan blijven de inzending en foto’s beschikbaar voor handmatige beoordeling.

Herhaalde, volledig uitgelezen etiketten met dezelfde identiteit, samenstelling, ingrediënten en analysewaarden kunnen hetzelfde product, dezelfde revisie en beoordeling gebruiken. Deze vergelijking is bewust strikt: onzekere velden, een onbekende analysebasis of ontbrekende identiteit leiden niet tot samenvoegen. Gelijktijdige gelijke labels worden onder een PostgreSQL-transactieslot verwerkt. Een overbodig leeg concept wordt gearchiveerd; de inzendingen en hun originele foto’s blijven afzonderlijk beschikbaar. Beheerderscorrecties wissen de automatische matchsleutel, zodat eerdere uitgelezen gegevens een correctie niet kunnen overschrijven.

De admin heeft afzonderlijke acties **Gegevens corrigeren** en **Ander recept toevoegen**. Een correctie behoudt de historie binnen hetzelfde product. Een ander recept maakt een nieuw product met een verwijzing naar het origineel; samenstelling, analyse en etiketbewijs worden niet uit het oude recept overgenomen. Producten hebben één actuele correctie. De controles voor bewerken, publiceren, etiketten toevoegen en scorecorrecties gebruiken dezelfde productgeschiedenis. Een binnenkomende scan maakt een openstaande beheerderscorrectie niet verouderd.

In **Ingezonden etiketten** staan de geselecteerde foto’s en uitleesgegevens per inzending. Goedkeuren rondt de wachtende inzendingen voor dat product af. Reeds afgeronde scans, ook voorlopige uitkomsten, blijven historische snapshots. Bij goedkeuring tijdens automatische verwerking krijgt de goedgekeurde beoordeling voorrang; een laat AI-antwoord overschrijft die niet.

Migratie `2026_10_26_170000_separate_feed_recipes_from_submissions` splitst bestaande, nog niet goedgekeurde inzendingsrecepten die onder een ander product zijn opgeslagen. Zij kopieert het bijbehorende correctie- en etiketbewijs naar een apart product en markeert de oorspronkelijke revisies als verplaatst. Bestaande scanresultaten, openbare resultaat-snapshots en beoordelingen blijven ongewijzigd. Al gepubliceerde historische recepten worden niet achteraf opnieuw ingedeeld. De migratie start geen AI-aanroepen.

## Activeren

1. Voer de normale databasemigraties uit (`sail artisan migrate --force`).
2. Stel **op de server** `FEED_SCANNER_OPENAI_API_KEY` in. `FEED_SCANNER_MODEL` staat standaard op `gpt-4.1` (beeldinvoer en gestructureerde uitvoer). Zet nooit een sleutel in Expo-configuratie of Git.
3. Herbouw/herlaad de Laravel-configuratie volgens de bestaande deploymentprocedure; herstart Octane/Sail zodat nieuwe routes, code en configuratie geladen zijn.
4. Bouw de admin en app zoals gebruikelijk. Zorg dat PHP/reverse-proxy/hosting een verzoek van ten minste 90 seconden toestaan. De uitleesaanroep heeft een eigen timeout van 60 seconden. Er is voor deze eerste versie geen aparte queueworker nodig.
5. Activeer de juiste **gepubliceerde** regelversie voor het standaardprofiel en balancerprofiel. Publiceer de ingrediëntenkaarten die gebruikt mogen worden.
6. Installeer op de Ubuntu-server eenmalig Tesseract met Nederlands en Engels: `sudo bash deploy/install-scanner-ocr.sh`. De lokale Sail-runtime bevat deze pakketten na `sail build`. `FEED_SCANNER_OCR_BINARY` kan een afwijkend pad aanwijzen; `FEED_SCANNER_OCR_LANGUAGES` is standaard `nld+eng`. Test met `tesseract --list-langs`. Bij ontbrekende OCR wordt nooit stilzwijgend AI ingezet voor de eerste controle; de gebruiker krijgt de route voor aanvullende foto’s.
7. Controleer in **Voerproducten → Bewerken → Samenstelling** de volledige ingrediënten- én toevoegingenlijst en vink **Herkennen op samenstelling inschakelen** aan. Goedkeuren publiceert deze referentie. Oude en automatisch uitgelezen versies zijn standaard niet ingeschakeld. Neem geen analysewaarden op in dit referentieveld. Bij een onvolledige referentie, bijvoorbeeld ontbrekende seleniumtoevoeging, vraagt de scanner aanvullende foto’s.
8. Controleer dat de PHP-appgebruiker kan lezen en schrijven in `storage/app/private/feed-labels`. Maak uploadmappen aan als appgebruiker; een door root aangemaakte map met modus `700` blokkeert browseruploads. Gebruik geen publieke opslag of wereldschrijfbare rechten als oplossing.

De live OpenAI-koppeling is nog niet getest: lokaal ontbreekt de API-sleutel. Laat de developer na configuratie één volledige inzending verwerken en de uitgelezen waarden, voorlopige uitkomst en review in de admin controleren. Bestaande ontvangen foto's kunnen opnieuw worden verwerkt; ze hoeven niet opnieuw te worden ingestuurd. Dit is geen verzoek om de nog niet gekalibreerde puntenweging te wijzigen.

Zonder API-sleutel worden foto’s wel opgeslagen en klaargezet bij **Voerproducten → Te beoordelen**. De app meldt dat automatisch uitlezen nog niet actief is. Een beheerder kan na configuratie via **Ingezonden etiketten → Etiket opnieuw uitlezen** opnieuw verwerken. Gebruikers kunnen dit ook vanuit Scanhistorie. Onleesbare etiketten, providerfouten en onvolledige uitvoer blijven ontvangen inzendingen; ze worden niet stilzwijgend een beoordeling.

## Beoordelen

AI leest uitsluitend etiketfeiten, met bronregels en betrouwbaarheid. De server koppelt alleen eenduidige exacte namen/synoniemen aan gepubliceerde ingrediëntenversies. Verwante en onzekere namen worden niet als bevestigde kenniskaart gebruikt. Onzekere waarden worden niet gebruikt voor scoreberekening. Een onbekende analysebasis wordt niet aangenomen.

Het bestaande `FeedAssessmentEngine` past de gepubliceerde scoreplafonds toe. De volledige puntenweging is nog niet gekalibreerd: er is geen willekeurig startcijfer of aftrek per ingrediënt toegevoegd. Daardoor leveren veel producten eerst bevindingen, ontbrekende informatie en eventueel een plafond op. Een aantoonbaar nulplafond bepaalt wel direct 0/10. Het oorspronkelijke beoordelingssnapshot bewaart product-, ingrediënt- en regelversies en blijft voorlopig. De beheerder kan de bestaande productworkflow gebruiken om gegevens te corrigeren, opnieuw te berekenen en goed te keuren; een AI-inzending publiceert nooit automatisch een product.

Inzendingen hebben eigen UUID’s en een idempotentiesleutel per gebruiker. Gelijktijdige analyseverzoeken delen een verwerkingsslot van drie minuten. Alleen expliciet opnieuw proberen kan na dat slot een nieuwe AI-aanroep starten. Uploads zijn maximaal 8 MB per foto, tien actieve foto’s en 20 MB cumulatief per inzending (ook vervangen bestanden tellen mee). Alleen JPEG, PNG en WebP zijn toegestaan. De apphistorie combineert online serverresultaten met bestaande lokale scans; oude beoordelingen worden niet herberekend.

## Controle

`sail artisan test --filter 'FeedComposition|FeedScan|FeedCatalog|FeedAssessment|FeedAdmin'` controleert eigenaarschap, private/idempotente uploads, de eerste controle zonder betaalde AI, actuele gepubliceerde matches, afzonderlijke producten voor gewijzigde recepten, gedeelde revisies voor identieke complete etiketten, behoud van oude scans bij migratie en correcties, gewijzigde percentages, onvolledige foto’s, gedeelde recepten, hervatten, de twee verplichte extra foto’s en de bestaande AI-inzendroute met nagebootste antwoorden. De OCR-test genereert een leesbaar testetiket en draait de echte lokale Tesseract. Er worden geen betaalde AI-verzoeken uitgevoerd. Voor een echte AI-uitleestest is na configuratie een representatief etiket nodig; controleer zowel de appuitkomst als brongegevens en foto’s in de admin.

Providerdocumentatie: [beeldinvoer](https://developers.openai.com/api/docs/guides/images-vision), [gestructureerde uitvoer](https://developers.openai.com/api/docs/guides/structured-outputs).

Lokale tekstherkenning: [Tesseract installeren](https://tesseract-ocr.github.io/tessdoc/Installation.html), [TSV en commandoregel](https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html).

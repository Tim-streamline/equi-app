# Voerscanner: foto’s verwerken

De app begint met één foto van de samenstelling. De server leest die lokaal met Tesseract en vergelijkt deze met gecontroleerde productversies. Voor deze stap worden geen betaalde AI-aanroepen gedaan. Bij een eenduidige match bevestigt de gebruiker het product en wordt de bestaande beoordeling geopend (of met de actieve regels opnieuw berekend, zonder AI).

Bij onbekende, gewijzigde, onvolledige of onzekere samenstelling vraagt de app ook de **analyse en voorkant**. Pas als alle drie de foto’s zijn ontvangen en de gebruiker verstuurt, begint de bestaande AI-etiketverwerking. Deze gegevens komen ter beoordeling in de bestaande admin. Foto’s blijven op de private `local`-disk. De gebruiker kan een inzending vanuit Scanhistorie hervatten, ook als er al foto’s zijn ontvangen.

Een herkend product heeft altijd een actuele, goedgekeurde productversie. De naam alleen, gedeeltelijke tekst of een vergelijkbaar recept volstaan niet. De vergelijking bewaart ingrediëntvolgorde, percentages en zichtbare toevoegingen; er worden geen fuzzy overeenkomsten als zeker behandeld. Gedeelde recepten van meerdere producten vragen extra bewijs. Een mogelijk gewijzigd recept krijgt een afzonderlijke conceptversie **binnen hetzelfde product** als de identiteit bekend is. De gepubliceerde versie, analyse en beoordeling blijven behouden; oude etiketfoto’s of analysewaarden worden niet automatisch als bewijs voor het nieuwe recept gekopieerd.

Een onzichtbare wijziging kan niet uit de eerste foto worden afgeleid. De bevestigingsstap biedt daarom ook **Samenstelling of etiketgegevens wijken af**, bijvoorbeeld als de analyse gewijzigd is terwijl de ingrediëntenlijst gelijk lijkt. Dat vraagt eveneens de twee aanvullende foto’s en opent een reviewversie.

Als de productidentiteit pas op de voorkant wordt gelezen, kan een eenduidige exacte merk-/naamovereenkomst de inzending alsnog aan een bestaand product koppelen **voor beoordeling**. Daarbij ontstaat geen bekende-score-match: het nieuwe etiket wordt zelfstandig beoordeeld. Het tijdelijke ongeïdentificeerde concept wordt gearchiveerd met behoud van oorspronkelijke bewijsmaterialen; de bestaande goedgekeurde productversie wordt niet gewijzigd.

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

Zonder API-sleutel worden foto’s wel opgeslagen en klaargezet bij **Voerproducten → Te beoordelen**. De app meldt dat automatisch uitlezen nog niet actief is. Een beheerder kan na configuratie via **Product bekijken → Etiket opnieuw uitlezen** opnieuw verwerken. Gebruikers kunnen dit ook vanuit Scanhistorie. Onleesbare etiketten, providerfouten en onvolledige uitvoer blijven ontvangen inzendingen; ze worden niet stilzwijgend een beoordeling.

## Beoordelen

AI leest uitsluitend etiketfeiten, met bronregels en betrouwbaarheid. De server koppelt alleen eenduidige exacte namen/synoniemen aan gepubliceerde ingrediëntenversies. Verwante en onzekere namen worden niet als bevestigde kenniskaart gebruikt. Onzekere waarden worden niet gebruikt voor scoreberekening. Een onbekende analysebasis wordt niet aangenomen.

Het bestaande `FeedAssessmentEngine` past de gepubliceerde scoreplafonds toe. De volledige puntenweging is nog niet gekalibreerd: er is geen willekeurig startcijfer of aftrek per ingrediënt toegevoegd. Daardoor leveren veel producten eerst bevindingen, ontbrekende informatie en eventueel een plafond op. Een aantoonbaar nulplafond bepaalt wel direct 0/10. Het oorspronkelijke beoordelingssnapshot bewaart product-, ingrediënt- en regelversies en blijft voorlopig. De beheerder kan de bestaande productworkflow gebruiken om gegevens te corrigeren, opnieuw te berekenen en goed te keuren; een AI-inzending publiceert nooit automatisch een product.

Inzendingen hebben eigen UUID’s en een idempotentiesleutel per gebruiker. Gelijktijdige analyseverzoeken delen een verwerkingsslot van drie minuten. Alleen expliciet opnieuw proberen kan na dat slot een nieuwe AI-aanroep starten. Uploads zijn maximaal 8 MB per foto, tien actieve foto’s en 20 MB cumulatief per inzending (ook vervangen bestanden tellen mee). Alleen JPEG, PNG en WebP zijn toegestaan. De apphistorie combineert online serverresultaten met bestaande lokale scans; oude beoordelingen worden niet herberekend.

## Controle

`sail artisan test --filter 'FeedComposition|FeedScanSubmissionTest'` controleert eigenaarschap, private/idempotente uploads, de eerste controle zonder betaalde AI, actuele gepubliceerde matches, gewijzigde percentages, onvolledige foto’s, gedeelde recepten, hervatten, de twee verplichte extra foto’s en de bestaande AI-inzendroute met nagebootste antwoorden. De OCR-test genereert een leesbaar testetiket en draait de echte lokale Tesseract. Er worden geen betaalde AI-verzoeken uitgevoerd. Voor een echte AI-uitleestest is na configuratie een representatief etiket nodig; controleer zowel de appuitkomst als brongegevens en foto’s in de admin.

Providerdocumentatie: [beeldinvoer](https://developers.openai.com/api/docs/guides/images-vision), [gestructureerde uitvoer](https://developers.openai.com/api/docs/guides/structured-outputs).

Lokale tekstherkenning: [Tesseract installeren](https://tesseract-ocr.github.io/tessdoc/Installation.html), [TSV en commandoregel](https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html).

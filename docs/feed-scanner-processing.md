# Voerscanner: foto’s verwerken

De app verstuurt samenstelling, analyse en eventuele extra etiketfoto’s naar de bestaande Laravel-server. Foto’s staan op de private `local`-disk, gekoppeld aan productversies en een inzending. Alleen de inzender kan de inzending/resultaten via de app ophalen; bestaande adminrechten blijven gelden voor het beoordelen en downloaden van bewijsmateriaal.

## Activeren

1. Voer de normale databasemigraties uit (`sail artisan migrate --force`).
2. Stel **op de server** `FEED_SCANNER_OPENAI_API_KEY` in. `FEED_SCANNER_MODEL` staat standaard op `gpt-4.1` (beeldinvoer en gestructureerde uitvoer). Zet nooit een sleutel in Expo-configuratie of Git.
3. Herbouw/herlaad de Laravel-configuratie volgens de bestaande deploymentprocedure; herstart Octane/Sail zodat nieuwe routes, code en configuratie geladen zijn.
4. Bouw de admin en app zoals gebruikelijk. Zorg dat PHP/reverse-proxy/hosting een verzoek van ten minste 90 seconden toestaan. De uitleesaanroep heeft een eigen timeout van 60 seconden. Er is voor deze eerste versie geen aparte queueworker nodig.
5. Activeer de juiste **gepubliceerde** regelversie voor het standaardprofiel en balancerprofiel. Publiceer de ingrediëntenkaarten die gebruikt mogen worden.

Zonder API-sleutel worden foto’s wel opgeslagen en klaargezet bij **Voerproducten → Te beoordelen**. De app meldt dat automatisch uitlezen nog niet actief is. Een beheerder kan na configuratie via **Product bekijken → Etiket opnieuw uitlezen** opnieuw verwerken. Gebruikers kunnen dit ook vanuit Scanhistorie. Onleesbare etiketten, providerfouten en onvolledige uitvoer blijven ontvangen inzendingen; ze worden niet stilzwijgend een beoordeling.

## Beoordelen

AI leest uitsluitend etiketfeiten, met bronregels en betrouwbaarheid. De server koppelt alleen eenduidige exacte namen/synoniemen aan gepubliceerde ingrediëntenversies. Verwante en onzekere namen worden niet als bevestigde kenniskaart gebruikt. Onzekere waarden worden niet gebruikt voor scoreberekening. Een onbekende analysebasis wordt niet aangenomen.

Het bestaande `FeedAssessmentEngine` past de gepubliceerde scoreplafonds toe. De volledige puntenweging is nog niet gekalibreerd: er is geen willekeurig startcijfer of aftrek per ingrediënt toegevoegd. Daardoor leveren veel producten eerst bevindingen, ontbrekende informatie en eventueel een plafond op. Een aantoonbaar nulplafond bepaalt wel direct 0/10. Het oorspronkelijke beoordelingssnapshot bewaart product-, ingrediënt- en regelversies en blijft voorlopig. De beheerder kan de bestaande productworkflow gebruiken om gegevens te corrigeren, opnieuw te berekenen en goed te keuren; een AI-inzending publiceert nooit automatisch een product.

Inzendingen hebben eigen UUID’s en een idempotentiesleutel per gebruiker. Gelijktijdige analyseverzoeken delen een verwerkingsslot van drie minuten. Alleen expliciet opnieuw proberen kan na dat slot een nieuwe AI-aanroep starten. Uploads zijn maximaal 8 MB per foto, tien actieve foto’s en 20 MB cumulatief per inzending (ook vervangen bestanden tellen mee). Alleen JPEG, PNG en WebP zijn toegestaan. De apphistorie combineert online serverresultaten met bestaande lokale scans; oude beoordelingen worden niet herberekend.

## Controle

`sail artisan test --filter FeedScanSubmissionTest` controleert eigenaarschap, private/idempotente uploads, ontbrekende foto’s/configuratie, nagebootste AI-uitvoer, kenniskaartkoppeling, onbekende analysebasis, nulplafond en herhaalde verzoeken. Er worden geen betaalde AI-verzoeken uitgevoerd. Voor een echte uitleestest is na configuratie een representatief etiket nodig; controleer zowel de appuitkomst als brongegevens en foto’s in de admin.

Providerdocumentatie: [beeldinvoer](https://developers.openai.com/api/docs/guides/images-vision), [gestructureerde uitvoer](https://developers.openai.com/api/docs/guides/structured-outputs).

# Scanner frontend

De vormgeving is gebaseerd op het aangeleverde `EquiApp Voerscanner.html`. De fictieve producten, regels, tijdgestuurde herkenning, uploads en scores zijn niet overgenomen.

De gedeelde Expo-schermen werken ook in de webapp: start, zoeken, gevonden product, lokale fotoselectie, eerdere analyses en historie. Producten komen uit de bestaande gesynchroniseerde catalogus; analyses en ingrediënten uit de eigen scanhistorie. Conceptproducten uit de admin worden niet opengesteld.

Bestaande scores blijven ongewijzigd op de historische schaal van 0–100 opgeslagen. Alleen de presentatie deelt door tien. De bestaande kleurbanden van 75/50 blijven behouden. Ontbrekende scores verschijnen als vraagteken. Eerdere analyses worden expliciet niet als berekeningen van de nieuwe motor gepresenteerd. Bij ontbrekende gestructureerde voedingswaarden worden geen percentages of grenzen verzonnen.

Camera en fotobibliotheek gebruiken de bestaande Expo ImagePicker. Geselecteerde foto's worden tijdens het navigeren binnen het startscherm behouden, maar nog niet verstuurd of geanalyseerd. Het scherm vermeldt dit expliciet. Bij sluiten, herladen of wisselen van account vervalt deze tijdelijke selectie.

De native cameratoestemming is in `app.json` ingeschakeld met een gebruikersgerichte toelichting. Dit vereist een nieuwe native build; alleen een JavaScript-update voegt de toestemming niet aan een al geïnstalleerde app toe. De fotobibliotheek blijft beschikbaar als alternatief.

De serverkoppeling voor productidentificatie, etiketherkenning, upload/review en gepubliceerde versiegebonden beoordelingen blijft afzonderlijk vervolgwerk. De fictieve API-routes uit het ontwerp worden niet aangeroepen. Er zijn geen database- of beoordelingsregelwijzigingen voor deze frontend nodig.

De visuele vergelijking van 25 oktober volgt de HTML-maten voor de zoekbalk in de kop, de grote productkaart met vaste actieknoppen onderaan, compacte recente scans, de scanhistorie en resultaatkaarten. De scannerbalk verwijst naar de bestaande accountpagina zoals het ontwerp. De echte systeemcamera/fotokiezer blijft in gebruik; de getekende camera en gesimuleerde herkennings- en analyseanimaties zijn geen werkende functies. Onderdelen waarvoor nog geen echte gegevens bestaan (zoals positieve punten en analytische tabellen) krijgen geen voorbeeldinhoud.

De recent overeengekomen introductie, lege scanhistorie, ondertitel “Wij beoordelen het voer voor je.” en toelichting direct onder het cijfer hebben voorrang op de teksten in het HTML-bestand. De opsomming van voercategorieën blijft verwijderd van het startscherm.

Het scherm voor onbekend voer volgt de aparte HTML-uploadpagina: een stericoon, “Dit voer kennen we nog niet!”, merk/productnaam, twee benodigde etikettenfoto’s (samenstelling en analyse), extra foto’s en een vaste onderbalk met het aantal ontbrekende foto’s. Een eerder geselecteerde voorkant blijft als aanvullende foto behouden en telt niet als een van de twee etikettenfoto’s. De onderbalk blijft uitgeschakeld zolang de verwerking niet is aangesloten; ook na twee lokale selecties wordt geen upload of beoordeling gesimuleerd. Bij een bekend product of een nog niet geïdentificeerde voorkant wordt niet beweerd dat het voer onbekend is.

De volledige controle van de 16 toestanden en twee aanvullende routes staat in `docs/design/scanner/controle.md`. Het optionele lokale paneel `/scanner/design-preview` gebruikt de gedeelde fotokaarten, vervangsheet, ontbrekende-waardekaarten, analysetabel, voortgangsweergave en camera-overlay. Alleen builds met `EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW=true` openen het paneel; het heeft geen menu-ingang in de gewone scanner en normale builds sturen door. De tabel onderscheidt werkende appschermen van voorbereide vormgeving waarvoor de technische verwerking nog ontbreekt.

# Controle van alle schermen en toestanden

**Update 25 oktober — echte verwerking:** de onderstaande tabel beschrijft de eerdere vormcontrole. De foto-inzendroute is inmiddels aangesloten op private uploads, server-side etiketherkenning, gepubliceerde regels/ingrediëntenkaarten, voorlopige resultaten, eigen historie en de bestaande productreview in de admin. De ontwerpinspecteur blijft alleen vormcontrole. Automatisch herkennen van een product op uitsluitend de voorkant en een eigen camerastream zijn nog niet aangesloten. Voor activering, de ontbrekende API-sleutel en de nog te kalibreren volledige puntenweging: zie [verwerking](../../feed-scanner-processing.md).

Referentie: het originele `EquiApp Voerscanner.html`, inclusief de 16 knoppen onder **Toestanden**, de twee keuzes voor herkenning en de twee keuzes voor de uitkomst na uploaden. Ook het vervangmenu en de route voor een andere samenstelling zijn meegenomen. De wijzigingen in `README.md` hebben voorrang op de referentie.

## Lokaal controleren

De webroute `/scanner/design-preview` toont dezelfde gedeelde componenten als de app, met een keuzepaneel. Alleen een lokale build met `EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW=true` opent deze route. Normale builds sturen door naar de scanner. De route heeft geen upload-, mutatie- of beoordelingsendpoint en slaat niets op.

Het paneel onderscheidt echte appschermen (eigen catalogus/scanhistorie) van expliciete vormvoorbeelden. Bij toekomstige analyses staan geen verzonnen producten, percentages of cijfers. Teksten in de bevindingenkaarten benoemen uitsluitend de plek waar later de echte bevinding verschijnt. Er wordt geen voorbeeldbeleid geïmporteerd.

| Toestand uit HTML | Vormgeving en huidig gedrag |
| --- | --- |
| Leeg / start | Titel, recente introductie, hoofdkaart, zoekroute, compacte historie en accountnavigatie. Echte historie; afgesproken lege tekst als er geen scans zijn. |
| Zoeken | Zoekveld in de kop, echte zoekresultaten, lege toestand en route voor onbekend voer. |
| Camera | Echte app gebruikt de systeemcamera van ImagePicker. Het aangepaste donkere kader, opnameknop, album en flitser zijn als overlay voorbereid en in het paneel te bekijken. Dit is nog **geen** eigen camerastream. |
| Opzoeken | Gedeelde laadweergave met voeranimatie, zonder timer die een product kiest. Productherkenning moet nog worden aangesloten. |
| Product gevonden | Grote kaart, categorie, productnaam en vaste acties. Geen statuslabel “gecontroleerd” zonder bron. Een bestaande eigen analyse kan worden geopend. |
| Product onbekend | Stericoon, titel, naamveld, twee etikettenfoto’s, optionele extra’s en vaste onderbalk. Geen belofte van een analyse die nog niet bestaat. |
| Uploaden bezig | Fotokaart met voortgangsbalk, gevoed door aangeleverde voortgang. Niet gekoppeld aan een echte upload; lokale fotoselectie wordt niet als “geüpload” gelabeld. |
| Onscherpe foto | Waarschuwing met “Opnieuw maken” en “Toch gebruiken” voorbereid. Er wordt geen scherptecontrole verzonnen. |
| Uploadfout | Foutkaart met herhaalactie voorbereid. Geen onterechte melding dat bestanden veilig op de server staan. |
| Analyse bezig | Voeranimatie en drie stappen. De aanroeper bepaalt de actieve stap; geen automatische overgang of verzonnen uitkomst. Verminderde beweging wordt gerespecteerd. |
| Voorlopige analyse | Statusbadge en resultaatcomponenten voorbereid. De echte app labelt historische analyses als “Eerdere beoordeling”; er wordt geen voorlopig nieuw resultaat toegekend. |
| Definitieve analyse | Vormcontrole via een echte eerdere analyse indien aanwezig. Geen onterecht “definitief”-label of nieuwe berekening. Nieuwe versiegebonden beoordelingen moeten nog worden aangesloten. |
| Waarde niet herkend | Oranje ontbrekende-waardestatus en fotoknop waar een werkende actie wordt aangeleverd. Geen nulwaarde en geen cijfer zonder bron. |
| Pure grasbrok · zetmeel niet vermeld | Neutrale “Niet vermeld”-weergave voorbereid. De voorbeeldclaim dat zetmeel doorgaans laag is, wordt niet als productfeit of scoreregel overgenomen. |
| Samengesteld · zetmeel niet vermeld | Neutrale ontbrekende waarde, totaal niet exact berekenbaar en uitklapbare tabel. Geen automatisch oordeel over een werkelijk product zonder metadata. |
| Scanhistorie | Eén grote titel, compacte kaarten met echte datum/cijfers, vraagteken bij ontbrekend cijfer. Status voorlopig/definitief wordt niet afgeleid uit de huidige productstatus. |
| Foto vervangen | Echt menu met camera, fotobibliotheek en annuleren. Bestaande selectie blijft behouden bij annuleren. |
| Andere samenstelling | Link vanuit een eigen analyse met productkoppeling opent de fotovariant voor dat catalogusproduct. Geen automatische wijziging of publicatie van de productversie. |

## Bewust niet overgenomen

- De fictieve producten, scores, voedingswaarden, beoordelingen, artikelen en API-routes uit het prototype.
- Een timer die upload, herkenning, scherptecontrole of analyse als geslaagd presenteert.
- De kleurgrens 7 uit het prototype: de bestaande presentatiebanden blijven behouden, zoals eerder afgesproken.
- De aanname dat een naam van één woord een verplichte extra foto vraagt. Dat is invoer-/identificatielogica en geen loutere vormgeving.
- De aanname dat “pure grasbrok” een onbekend zetmeelgehalte toch voldoende betrouwbaar of gunstig maakt.

De toekomstige verwerking en versiegebonden beoordelingen vereisen nog een afzonderlijke koppeling. Dit overzicht bevestigt de visuele dekking en benoemt de functionele grenzen; het claimt niet dat de gehele scannerketen al werkt.

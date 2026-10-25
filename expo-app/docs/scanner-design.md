# Scanner frontend

De vormgeving is gebaseerd op het aangeleverde `EquiApp Voerscanner.html`. De fictieve producten, regels, tijdgestuurde herkenning, uploads en scores zijn niet overgenomen.

De gedeelde Expo-schermen werken ook in de webapp: start, zoeken, gevonden product, lokale fotoselectie, eerdere analyses en historie. Producten komen uit de bestaande gesynchroniseerde catalogus; analyses en ingrediënten uit de eigen scanhistorie. Conceptproducten uit de admin worden niet opengesteld.

Bestaande scores blijven ongewijzigd op de historische schaal van 0–100 opgeslagen. Alleen de presentatie deelt door tien. De bestaande kleurbanden van 75/50 blijven behouden. Ontbrekende scores verschijnen als vraagteken. Eerdere analyses worden expliciet niet als berekeningen van de nieuwe motor gepresenteerd. Bij ontbrekende gestructureerde voedingswaarden worden geen percentages of grenzen verzonnen.

Camera en fotobibliotheek gebruiken de bestaande Expo ImagePicker. Geselecteerde foto's worden tijdens het navigeren binnen het startscherm behouden, maar nog niet verstuurd of geanalyseerd. Het scherm vermeldt dit expliciet. Bij sluiten, herladen of wisselen van account vervalt deze tijdelijke selectie.

De native cameratoestemming is in `app.json` ingeschakeld met een gebruikersgerichte toelichting. Dit vereist een nieuwe native build; alleen een JavaScript-update voegt de toestemming niet aan een al geïnstalleerde app toe. De fotobibliotheek blijft beschikbaar als alternatief.

De serverkoppeling voor productidentificatie, etiketherkenning, upload/review en gepubliceerde versiegebonden beoordelingen blijft afzonderlijk vervolgwerk. De fictieve API-routes uit het ontwerp worden niet aangeroepen. Er zijn geen database- of beoordelingsregelwijzigingen voor deze frontend nodig.

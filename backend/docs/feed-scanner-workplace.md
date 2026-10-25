# Voerscanner: dagelijkse beheerwerkplek

De navigatie bestaat uit Overzicht, Voerproducten, Kennisbank, Scanhistorie en Beoordelingsinstellingen. De bestaande product-, ingrediënten-, versie-, kenniskaart- en scantabellen blijven in gebruik. Oude URL's blijven werken.

Het overzicht telt werkelijk opgeslagen producten, gepubliceerde ingrediëntenkenniskaarten plus voedingskundige kenniskaarten, openstaande beoordelingen en scans binnen de kalendermaand in Europe/Amsterdam. Taken verschijnen alleen als er werk in de database aanwezig is. Alle bewaarde producten tellen mee in het totaal, ook gearchiveerde producten. De productfilters gebruiken de nieuwste reviewstatus: informatie ontbreekt wordt apart van te beoordelen getoond. `review=1` blijft als oude link naar te beoordelen werken.

De kennisbank gebruikt twee tabbladen over de bestaande beheerschermen. Uitlegkaarten wijzigen geen scoreregels. Bestaande autorisaties en audit-/versiehistorie blijven behouden. Verwijderen van ingrediënten is een herstelbare status `removed` in de bestaande cataloguskolom: de kaart verdwijnt uit de actieve lijst en keuzelijsten, terwijl IDs, koppelingen, revisies en oude scans intact blijven. Via de filter Verwijderd kan een beheerder herstellen naar gearchiveerd. Herstellen publiceert nooit automatisch. De bestaande PowerSync-selectie synchroniseert uitsluitend legacy/gepubliceerde kaarten en sluit deze verwijderde status al uit; er is geen extra databasekolom of migratie nodig.

Beoordelingsinstellingen tonen het actieve algemene beleid één keer (of het concept wanneer nog niets actief is). Andere categorieën worden alleen genoemd bij inhoudelijke verschillen. Regelconcepten, activering en versiehistorie staan onder Geavanceerde instellingen. Er zijn geen berekeningen, scoregrenzen of beleidsversies veranderd door deze vereenvoudiging.

## Bevestigd scorebereik 0–10

Bij controle van de lokale database op 25 oktober 2026 bevatten beide actieve profielen een regel **vanaf 20% suiker + zetmeel: maximaal 0/10**:

- Balancer: gepubliceerde regelversie 2.
- Standaardvoer: gepubliceerde regelversie 3.

Op 25 oktober 2026 is expliciet bevestigd dat een voer ook 0/10 mag scoren. Het bereik blijft dus 0–10; de eerdere vermelding van 1–10 in de ontwerpbrief is vervallen. De bestaande minimumvalidatie voor plafonds en handmatige beoordelingen accepteert nul. De instellingenpagina toont de regels voor nul uit de werkelijke gegevens en onderscheidt actief, concept en historisch. De motor, grenzen, bestaande beoordelingen en bevroren migratiemanifesten blijven ongewijzigd.

Bij historische scans is geen snapshot opgeslagen van voorlopig/definitief. De admin toont daarom **Niet vastgelegd**, in plaats van de huidige productstatus als historische waarheid te gebruiken. De opgeslagen legacy-scores worden als oorspronkelijke scores /100 getoond. Een koppeling met toekomstige versiegebonden scannerresultaten blijft afzonderlijk werk.

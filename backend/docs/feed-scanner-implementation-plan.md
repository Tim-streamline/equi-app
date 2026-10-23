# Voerscanner: plan voor admin en database

Status: ontwerp, 22 oktober 2026. De eerste beheerfase wordt uitgevoerd; zie `feed-scanner-admin.md` voor de daadwerkelijk gebouwde, vereenvoudigde invulling.
Inventarisatie op basis van de repository; geen productiegegevens of live databaseschema geïnspecteerd.

## 1. Vastgelegde uitgangspunten

- Score 0–10: aansluiting bij de voedingsfilosofie van De Paardentherapeut. Geen persoonlijke geschiktheidsbeoordeling in versie 1.
- Categorieën: compleet paardenvoer, aanvullend voer, balancer, slobber. Categorie en beoordelingsprofiel zijn afzonderlijke velden: balancers krijgen een eigen profiel.
- Suiker en zetmeel uitsluitend samen beoordelen, standaard op productbasis. De grenzen 7,0% en 10,0% als twee benoemde beleidsbanden tonen; zonder paardenprofiel niet automatisch een gezondheidsklasse kiezen. Nog geen puntenaftrek aan deze banden verbinden.
- Seleniumplafonds: bevestigd organisch 3,0; bevestigd toegevoegd met onbekende vorm 5,5; bevestigd anorganisch geen seleniumplafond. Alleen een analytische totaalwaarde activeert geen plafond. Bij meerdere bevestigde relevante vormen geldt het strengste plafond.
- Blacklistbeleid en expliciete uitzonderingen zijn beheerbare regels. 'Kleine hoeveelheid' vereist een vastgelegde, toetsbare voorwaarde; geen aanname op basis van alleen de positie op het etiket.
- AI stelt extracties en koppelingen voor. De server voert uitsluitend gepubliceerde, gevalideerde regels uit. Een etiket kan geen instructies of nieuwe beleidsregels leveren.
- Definitieve puntenweging blijft leeg totdat echte producten zijn gekalibreerd. Ontbrekende score betekent niet 0/10.

## 2. Aangetroffen structuur

| Bestaand | Velden/gedrag | Hergebruik |
|---|---|---|
| `products` / `Product` | UUID, brand, name, unieke optionele barcode, vrije category, needs_review, timestamps | Bestaande productidentiteit en IDs behouden; uitbreiden met levenscyclus, herkomst en gepubliceerde versie |
| `ingredients` / `Ingredient` | UUID, unieke name, description, default_tag good/warn/danger, needs_review, timestamps | Bestaande ingrediëntidentiteit behouden; uitbreiden tot kenniskaart |
| `ingredient_product` | product_id + ingredient_id samengestelde primaire sleutel, order, vrije amount, timestamps | Bestaande samenstelling overnemen in versie 1; daarna uitsluitend compatibiliteitsprojectie van gepubliceerde samenstelling |
| `scan_results` / `ScanResult` | user_id, optioneel horse_id/product_id, naam/merk-snapshot, scanned_at, integer score, rating, advice, photo_url, bookmarked, flagged | Gebruikersscan en historie behouden; later verwijzen naar afzonderlijke beoordelingen |
| `scan_ingredients` / `ScanIngredient` | scan_id, optioneel ingredient_id, naam/tag/uitleg-snapshot, order | Historische scanweergave behouden; later uit een beoordeling vullen |
| `audit_logs` / `AuditLogger` | admin, actie, before/after, reden, tijd | Alle beheer-, review-, publicatie- en correctieacties vastleggen |
| `library_items`, `users`, `admin_users`, `horses` | Bestaande referenties | Bibliotheeklinks, auteurschap, inzenders en toekomstige paardcontext |

Admin: `IngredientController`, `ProductController`, `ScanResultController` en Svelte-pagina's Ingredients/Products/Scans bestaan. Productbeheer ondersteunt ingrediënten toevoegen, verwijderen, ordenen en een vrije hoeveelheid; daarvoor bestaan featuretests. Zoekfuncties en reviewfilters hergebruiken.

De scannerpagina heeft momenteel een animatie en opent bij de scanknop een bestaand resultaat; daar staat nog geen echte camera-/OCR-keten. De resultaatpagina werkt met scores op 0–100 en presenteert bestaande tekst als advies voor het geselecteerde paard. Dit later expliciet aanpassen naar algemene productbeoordeling en weergave op 0–10; historische scores niet ongemerkt als nieuwe beoordelingen presenteren.

## 3. Uitbreidingen en nieuwe tabellen

Alle nieuwe tabellen krijgen UUID, foreign keys en passende tijdvelden. Numerieke hoeveelheden en scores gebruiken decimalen; onbekend wordt NULL, nooit nul. Gepubliceerde versies worden niet overschreven. Archiveren vervangt verwijderen zodra een record in historie wordt gebruikt.

### Ingrediëntenkennis

`ingredients` uitbreiden: status (concept/gepubliceerd/gearchiveerd), categorie, interne notities, created_by_admin_id, reviewed_by_admin_id, reviewed_at en published_revision_id. Bestaande description/default_tag blijven compatibel; een kleur is geen scoreregel.

Nieuwe tabellen:

1. `ingredient_revisions`: ingredient_id, revision_number, status, gepubliceerde naam/categorie/uitleg, basisbeoordeling, uitzonderingsuitleg, optioneel library_item_id, auteurschap/publicatiegegevens. Uniek (ingredient_id, revision_number). Bij wijzigen gepubliceerde inhoud ontstaat een nieuw concept.
2. `ingredient_terms`: ingredient_revision_id, originele term, genormaliseerde term, taal, type exact_synonym/related_term. Exacte synoniemen kunnen automatisch koppelen; verwante termen uitsluitend als suggestie. Ambigue exacte termen voor meerdere ingrediënten blokkeren publicatie totdat ze zijn opgehelderd. Onzekere AI-koppelingen horen bij productregels/inzendingen, niet in de lijst met bevestigde synoniemen.

Blacklistvermelding en machineleesbare hoeveelheidsuitzonderingen staan in de regels; kenniskaarten bevatten de uitleg. Zo kan tekst wijzigen zonder ongemerkt een berekening te veranderen.

### Beoordelingsregels en voedingskundige kennis

3. `feed_knowledge_cards`: stabiele code, titel, onderwerp, uitleg, status, interne notities, optioneel library_item_id, auteur/reviewer. Bijvoorbeeld suiker + zetmeel en seleniumvormen. Gepubliceerde regelversies bewaren de gebruikte uitleg als snapshot.
4. `feed_assessment_profiles`: code, naam, beschrijving, status. Start met standaardvoer en balancer; categorieën verwijzen expliciet naar een profiel. Geen arbitraire balancerregels invullen.
5. `feed_rule_versions`: profile_id, version_number, status, rules_schema_version, regels als gevalideerde JSON, vereiste invoergegevens, kennis-snapshot, verwijzingen/snapshots van ingrediëntrevisies, publicatiegegevens, calibration_status en content_hash. Uniek (profile_id, version_number). Hoogstens één actieve gepubliceerde versie per profiel.

Regels worden via formulieren beheerd: invoerwaarde, vergelijking, grenswaarde, eenheid/basis, toepassingsvoorwaarde, effect en uitleg. Alleen ondersteunde regeltypen; geen uitvoerbare PHP/SQL of vrije AI-instructies. De evaluator en validatie blijven geteste programmacode; concrete beleidswaarden zijn beheerbaar.

In fase 1 de opgegeven beleidsgrenzen/plafonds als concept vastleggen, zonder verzonnen gewichten, basiscijfer of afkeurgrenzen. Publicatie als volledig scoreprofiel vereist kalibratie. Een uitlegkaart publiceren hoeft daarop niet te wachten.

### Producten en samenstellingen

`products` uitbreiden: status, gecontroleerde category_code naast bewaarde legacy category, assessment_profile_id, published_version_id, created_by_user_id of created_by_admin_id, source_kind, interne notities en soft-delete/archive-ondersteuning. Merk voorlopig als bestaand tekstveld behouden: een aparte merkentabel is niet nodig voor deze fase.

6. `product_versions`: product_id, version_number, review_status (concept/te_beoordelen/info_ontbreekt/goedgekeurd/afgewezen), originele samenstellingstekst, gebruik/dosering, optionele oorspronkelijke vocht-/drogestofinformatie, bron, source_observed_at, content_hash, reviewed_at, reviewer en review_notes. Uniek (product_id, version_number). Gepubliceerde vorige versie blijft beschikbaar zolang een nieuw concept wordt gecontroleerd.
7. `product_version_ingredients`: eigen UUID, product_version_id, optioneel ingredient_id en ingredient_revision_id, originele etiketnaam, order, originele amount, getalswaarde/unit/basis indien bekend, match_status (onbekend/voorgesteld/bevestigd), match_method, evidence_reference en confidence indien geleverd. Herhaalde of onbekende ingrediënten mogen worden vastgelegd. Voor toevoegingen ook substance_code, chemical_form, addition_status en verificatiegegevens. Zo blijven seleniumvorm en bewijs afzonderlijk van de totaalwaarde.
8. `product_version_nutrients`: product_version_id, nutrient_code, originele waarde/eenheid/tekst, numerieke waarde, qualifier (=/< />/range), analysebasis (product/droge_stof/onbekend), bron en verificatiestatus. Startcodes o.a. suiker, zetmeel, vocht, droge stof, eiwit, ruwe celstof, calcium, fosfor, selenium totaal. Eén canonieke waarde per code/basis; conflicterende bronnen blijven als inzending bewaard en vragen review.

Conversie naar productbasis alleen met expliciete analysebasis en betrouwbare vocht/drogestofwaarde van dezelfde productversie. Bewaar oorspronkelijke waarden en gebruikte conversiegegevens. Suiker en zetmeel eerst op dezelfde basis/eenheid brengen, dan optellen. Bij ontbrekende informatie geen nul invullen of basis raden.

`ingredient_product` blijft tijdelijk voor bestaande code: migratie kopieert naar versie 1; vervolgens schrijft één versie-publicatieservice de projectie terug. Geen onafhankelijke bewerking via twee systemen. Onbekende samenstellingsregels blijven in de versie bewaard, ook als de legacy pivot ze niet kan weergeven.

### Etiketten, inzendingen en AI-voorbereiding

9. `feed_label_submissions`: product_id/product_version_id optioneel, inzender (user of admin), source_kind, opgegeven merk/naam/barcode, status, duplicate_candidate_id optioneel, raw_text, extracted_payload, extraction_schema_version, provider/model/prompt_version, processing_status, foutgegevens en reviewbeslissing. Iedere inzending blijft afzonderlijk terugvindbaar; meerdere inzendingen kunnen naar hetzelfde product en dezelfde versie wijzen. Productversies ontstaan pas na vergelijking met bestaande samenstelling.
10. `feed_label_attachments`: submission_id, optioneel product_version_id, doel (verpakking/samenstelling/analyse), disk/path, bestandsnaam, MIME, grootte, checksum en uploader. Meerdere foto's mogelijk, inclusief één foto met beide etiketten. Geen verplichting tot exact twee bestanden.

Bestaande `media_assets` is gericht op de bibliotheek en publieke URL's; het model kan bij verwijderen ook bibliotheekafbeeldingen aanpassen. Daarom geen voeretiketten aan bibliotheekrecords hangen. Wel bestaande Laravel-opslag, uploadvalidatie en autorisatiepatronen hergebruiken; een kleine eigen etiketten-bijlagentabel bewaakt toegang tot onbewerkte gebruikersuploads. Publieke productafbeeldingen alleen expliciet publiceren.

Barcode is een sterke productidentificatie, maar bewijst geen ongewijzigde samenstelling. Bij naamovereenkomst alleen een duplicaatsuggestie; bij afwijkende samenstelling review van een nieuwe versie. Herhaalde verzending van dezelfde inzending afvangen met een idempotency key. Hashes ondersteunen vergelijking, vervangen de inhoudelijke review niet.

### Beoordelingen en handmatige correcties

11. `feed_assessments`: product_version_id, rule_version_id, optioneel scan_result_id, scope (product/horse), horse_id voorlopig NULL, input_snapshot, input_hash, engine_version, status (onvoldoende_data/voorlopig/definitief), automatic_score decimal nullable, applied_cap nullable, bevindingen, ontbrekende gegevens, berekeningstrace en calculated_at. Historische beoordelingen nooit overschrijven; herberekening maakt een nieuwe rij met optioneel supersedes_assessment_id. Versies en snapshots maken een beoordeling reproduceerbaar, ook als stamgegevens later wijzigen.
12. `feed_assessment_overrides`: assessment_id, corrected_score decimal, verplichte motivatie, admin_id, created_at en optionele verwijzing naar ingetrokken/vervangen correctie. Automatische score blijft intact. Geen correctie boven het geldende harde plafond toestaan. Zonder automatische score geen onverklaarde eindscore fabriceren.

`scan_results` later uitbreiden met current_assessment_id en product_version_id. Bestaande horse_id biedt de toekomstige contextkoppeling al. Algemene productscore kan gedeeld worden; een latere paardbeoordeling krijgt een aparte assessment met een snapshot van relevante profielgegevens en aparte regels. Er komt nu geen paardenprofielberekening.

## 4. Migraties en overgang

Voorgestelde namen zijn beschrijvend; definitieve timestamps bij bouwen bepalen, na bestaande migraties.

| Volgorde | Migratiegroep | Uitvoering |
|---|---|---|
| 1 | `extend_ingredient_knowledge_and_create_revisions` | Ingrediëntvelden, revisies en termen; bestaande IDs behouden |
| 2 | `create_feed_knowledge_and_rule_profiles` | Kenniskaarten, profielen, regelversies; uitsluitend afgesproken conceptbeleid en lege kalibratie |
| 3 | `create_product_versions_and_composition` | Productvelden, versies, samenstellingsregels, analytische waarden; benodigde pointers na doeltabellen toevoegen |
| 4 | `create_feed_label_submissions_and_attachments` | Herkomst, handmatige uploads, review en latere extractievelden |
| 5 | `backfill_legacy_feed_catalog` | Hervatbare, idempotente kopie van bestaande ingrediënten/pivot/producten naar revisie/versie 1; nooit opnieuw seeden/resetten |
| 6 | `create_feed_assessments_and_overrides` | Structuur voor herleidbare beoordelingen/correcties; in fase 1 lege historie, geen scoremotor |
| 7, later | `link_scans_to_versioned_assessments` | Nieuwe scanner aan assessments koppelen; compatibiliteit en sync beveiligen voordat resultaten worden gebruikt |

Legacygegevens niet automatisch wetenschappelijk/inhoudelijk goedgekeurd verklaren. Bestaande tags beschrijvend overnemen, vrije hoeveelheden behouden zonder onzekere omzetting. Oude producten zonder betrouwbare categorie ter controle aanbieden. Oude scans en scores ongemoeid laten, markeren als legacy zodra nieuwe weergave wordt aangesloten. Geen oude scan aan een verzonnen regel- of samenstellingsversie koppelen.

Legacy catalogus mag tijdens de overgang beschikbaar blijven; alleen expliciet gepubliceerde nieuwe versies gaan later naar nieuwe beoordelingen. Reeds gebruikte versies via foreign keys beschermen tegen verwijderen. Rollback verwijdert geen gebruikershistorie; terugschakelen via featureflag en compatibiliteitslaag. Voor deployment een back-up en migratiedry-run op een kopie.

## 5. Admin: eerste bouwfase

Drie beheeronderdelen onder Voerscanner:

1. **Ingrediënten**: zoeken/filteren; naam, categorie, basisbeoordeling, uitleg, uitzonderingsuitleg, termen met taal/type, bibliotheeklink, interne notities, status en revisiehistorie beheren.
2. **Beoordelingsregels & kennis**: uitlegkaarten en aparte profiel-/regelversies; herkenbare formulieren voor grenzen, voorwaarden, seleniumbewijs en uitzonderingen; concept kopiëren/publiceren; geen willekeurige puntenweging.
3. **Merken & producten**: overzicht met merk/product/categorie, versie, status, toegevoegd/laatst gecontroleerd en aantal scans. Detailtabs Algemeen, Samenstelling, Analyse, Etiketten, Beoordelingen en Historie. Te beoordelen is een opgeslagen filter/werkvoorraad binnen dit onderdeel met teller op het dashboard; geen extra productdatabase. Sorteren op scans telt bestaande scan_results, niet handmatig bij te houden aantallen.

Reviewacties: Goedkeuren, Aanpassen en goedkeuren, Meer informatie nodig. Approval is één transactie: versie valideren, vastzetten, publicatiepointer/projectie bijwerken en audit vastleggen. Gegevensgoedkeuring staat los van een beschikbare eindscore. Toon in Beoordelingen voorlopig 'Puntenweging nog niet gekalibreerd'. Handmatige scorecorrectie wordt pas actief zodra een echte assessment bestaat.

Serverautorisatie voor bekijken/bewerken/publiceren, niet alleen verborgen navigatielinks. Voorstel: owner/admin publiceren en corrigeren; content_editor concepten beheren. Bestaande routes waar mogelijk behouden. Concurrente wijzigingen detecteren met revisienummer/updated_at; geen stil overschrijven.

## 6. Synchronisatie en beveiliging

De huidige PowerSync-regels selecteren `products` en `ingredients` met `SELECT *` voor abonnees. Vóór toevoegen van interne velden expliciete publieke kolommen selecteren. Concepten, interne notities, gebruikersuploads, extraction_payloads en reviewgegevens niet algemeen synchroniseren. Gepubliceerde inhoud kan later via gerichte read-only sync/API naar de app.

Scanresultaten en scaningrediënten zijn nu door eigenaren schrijfbaar via `SyncAccessPolicy`; dit maakt een opgeslagen score nog geen door de server berekende score. Bij aansluiten nieuwe scanner mogen clients alleen aanvragen en toegestane gebruikersvoorkeuren insturen, nooit automatische scores, plafonds of approved ingredient matches bepalen. Ownership alleen is daarvoor onvoldoende. Privéresultaten blijven aan bestaande gebruiker-/paardtoegang gebonden.

Nieuwe server-tabellen niet automatisch toevoegen aan de uploadallowlist. Ruwe etiketten privé opslaan met geautoriseerde toegang; gepubliceerde productdata bevat geen inzendergegevens. Bewaartermijnen voor uploads los van gepubliceerde catalogus/historie bepalen vóór publieke uploads.

## 7. Acceptatiecriteria en fasering

Fase 1 opleverbaar wanneer:

- Je alle drie onderdelen kunt vullen, heropenen, wijzigen, archiveren en zoeken.
- Een gepubliceerd ingrediënt/product/regelpakket alleen via een nieuw concept kan veranderen.
- Oude IDs, productkoppelingen, scans en vrije bronwaarden behouden zijn; backfill herhalen maakt geen duplicaten.
- Analysebasis, eenheid, oorspronkelijke waarde en bron bewaard zijn; onzekere waarden blijven onbekend.
- Exacte synoniemen, verwante termen en onzekere voorstellen verschillend worden behandeld.
- Reviewqueue, dashboardteller, scanfrequentie en historie uit dezelfde records volgen.
- Concepten/interne velden niet naar algemene app-sync lekken; publicatie, review en overrides servermatig geautoriseerd zijn.
- Bestaande productbeheer-/synctests blijven slagen; nieuwe tests versie-immutabiliteit, migratiebehoud, reviewtransacties, toegang en publicatiesnapshots dekken.

Fase 2: kalibratie met echte producten; deterministische beoordelingsmotor, conversies, bewijsvoorwaarden, minimale invoer, herberekeningen/verschillen en gemotiveerde correcties. Test onder andere totaal-selenium zonder toevoegingsbewijs, organisch plafond, onbekende toegevoegde vorm, meerdere vormen, onbekende analysebasis en ontbrekend zetmeel.

Fase 3: gebruikersscanner, herkenning/AI-extractie, gebruikerscontrole van onzekere waarden, ontdubbelen, automatische reviewinstroom en voorlopige scores zodra dat verantwoord kan worden berekend. Model/prompt/extractieversie en oorspronkelijke bewijsstukken blijven herleidbaar.

Nog te bepalen vóór fase 2: exacte essentiële gegevens per profiel, beleid voor confidence/kwaliteitscontrole, de totale puntenweging en classificatiegrenzen. Voor fase 1 blokkeren die keuzes het toevoegen en beheren van kennis en producten niet.

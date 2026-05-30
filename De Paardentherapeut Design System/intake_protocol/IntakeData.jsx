/* global window */
// IntakeData.jsx, mock data voor het therapeut-dashboard.
// FILLED_INTAKE bevat een ingevulde intake voor één paard (Nova).
// CONCEPT_PROTOCOL is wat het systeem op basis daarvan klaarzet.
// CLIENT_MONITORING zijn lopende protocollen voor de monitoring-view.

const INTAKE_SECTIONS = [
  { id: 'contact',       nr: 0,  title: 'Contactgegevens',    sub: 'E-mail, telefoon, openheid voor aanpassingen', ic: 'mail',       minutes: 2, status: 'done' },
  { id: 'paard',         nr: 1,  title: 'Over je paard',         sub: 'Ras, leeftijd, gewicht, conditie', ic: 'horse',     minutes: 4, status: 'done' },
  { id: 'klacht',        nr: 2,  title: 'Klacht & hulpvraag',    sub: 'Wie is je paard, wat speelt er, wat wens je', ic: 'alert',     minutes: 6, status: 'done' },
  { id: 'geschiedenis',  nr: 3,  title: 'Geschiedenis van je paard', sub: 'De eerste levensjaren, medisch verleden', ic: 'history',   minutes: 5, status: 'done' },
  { id: 'medisch',       nr: 4,  title: 'Medisch & specialisten',sub: 'Vaccinaties, gebit, hoeven enz.', ic: 'heart',     minutes: 6, status: 'done' },
  { id: 'voer',          nr: 5,  title: 'Voer & ruwvoer',        sub: 'Hooi, krachtvoer, mineralen, supplementen', ic: 'leaf',      minutes: 12, status: 'active' },
  { id: 'water',         nr: 6,  title: 'Water & uitscheiding',  sub: 'Vochtinname, urine, mest', ic: 'droplet',   minutes: 5, status: 'todo' },
  { id: 'huisvesting',   nr: 7,  title: 'Huisvesting & weide',   sub: 'Zomer + winter, sociale groep, grasland', ic: 'home',      minutes: 8, status: 'todo' },
  { id: 'gedrag',        nr: 8,  title: 'Gedrag & training',     sub: 'Discipline, stalondeugden, karakter', ic: 'sparkles',  minutes: 6, status: 'todo' },
  { id: 'fysiek',        nr: 9,  title: 'Fysiek & foto\'s',      sub: 'Huid, vacht, hoeven, ademhaling', ic: 'camera',    minutes: 10, status: 'todo' },
  { id: 'samenvatting',  nr: 10, title: 'Versturen',             sub: 'Controleer & stuur naar Shelley', ic: 'send',      minutes: 2, status: 'todo' },
];

/* ---- Volledig ingevulde intake, Nova ---- */

const FILLED_INTAKE = {
  meta: {
    id: 'INT-2026-051',
    submitted: '14 mei 2026 · 21:48',
    klant: {
      naam: 'Marit van der Berg',
      email: 'marit@voorbeeld.nl',
      tel: '06 24 18 03 09',
      since: 'Sinds april 2026 · Het Holistisch Herstelplan',
      av: 'M',
    },
    paard: {
      naam: 'Nova',
      ras: 'Friese kruising',
      geslacht: 'merrie',
      leeftijd: '9 jaar',
      gewicht: '540 kg',
      stokmaat: '162 cm',
      conditie: 'iets te zwaar (BCS 6/9)',
    },
  },

  /* Antwoorden per sectie. kind = 'photo' / 'flag' / 'critical' / 'protocol'.
     'protocol' = dit antwoord triggert een specifieke protocol-aanpassing. */
  answers: {
    contact: [
      { q: 'E-mailadres', a: 'marit@voorbeeld.nl' },
      { q: 'Telefoon', a: '06 24 18 03 09' },
      { q: 'Hoe gevonden?', a: 'Via Instagram, en een vriendin van de stal raadde Shelley\'s podcast aan.' },
      { q: 'Open voor aanpassingen in voer/management?', a: 'Ja, helemaal, ik wil dit echt aanpakken.' },
    ],
    paard: [
      { q: 'Naam · ras · geslacht', a: 'Nova · Friese kruising · merrie' },
      { q: 'Geboortedatum / leeftijd', a: '12 mei 2017 · 9 jaar' },
      { q: 'Gewicht (methode)', a: '540 kg · gemeten met gewichts-lint',
        kind: 'protocol', meta: 'Alle doseringen herrekenen: 0.9× standaard (basis = 600 kg)' },
      { q: 'Stokmaat', a: '162 cm · gemeten' },
      { q: 'Adres stal', a: 'Manege De Hoeve · Hoeflaan 14 · 3941 GH Doorn' },
      { q: 'Sinds wanneer in bezit?', a: 'Sinds zomer 2022' },
      { q: 'Eerste eigenaar?', a: 'Nee, tweede eigenaar' },
      { q: 'Drachtig / lacterend?', a: 'Nee' },
      { q: 'Conditie', a: 'iets te zwaar (BCS 6/9)', kind: 'flag' },
      { q: 'Conditie veranderd sinds in bezit?', a: 'Begon slank, is geleidelijk wat te zwaar geworden, vooral sinds winter 2024.' },
      { q: 'Gezondheidsstatus in eigen woorden',
        a: 'Op zich gezond paard, maar de jeuk verziekt onze samenwerking. Energie wisselend. Soms wat lusteloos in voorjaar.' },
      { q: 'Foto paard (zijaanzicht)', a: '', kind: 'photo' },
    ],
    klacht: [
      { q: 'Hulpvraag',
        a: 'Aanhoudende jeuk op manen en staartwortel. Schuurt zich aan paddockhek. Verergert in mei/juni.' },
      { q: 'Wanneer begonnen + omstandigheden',
        a: 'Vorig voorjaar voor het eerst opgevallen, dit jaar erger. Begon ongeveer 3 maanden na verhuizing.', kind: 'flag' },
      { q: 'Subklacht',
        a: 'Mest af en toe wat losser dan normaal, vooral als ze net gras kreeg.', kind: 'protocol',
        meta: 'Wijst op darm-instabiliteit' },
      { q: 'Wens van dit traject',
        a: 'Begrijpen waar het vandaan komt en het van binnenuit aanpakken, geen prik of pillen meer.' },
      { q: 'Acute klachten nu?', a: 'Geen' },
      { q: 'Thema\'s', a: 'Jeuk · Darmen · Voeding · Gedrag' },
      { q: 'Onder behandeling DA / diagnose?', a: 'Niet meer actief. Vorig jaar antihistaminicum. Geen vaste diagnose.' },
      { q: 'Eerder behandeld?', a: 'Antihistaminicum (zomer 2025), 6 weken. Klacht kwam na 6 weken terug.' },
      { q: 'Huidige aanpak',
        a: 'Probeer hooi vroeger uit te halen voor het droogt. Geen medicatie nu.' },
      { q: 'Bloedonderzoek', a: '2× rapport (2024, 2025), geüpload', kind: 'photo' },
      { q: 'Foto\'s historie (2-5 jr)', a: '6 foto\'s geüpload', kind: 'photo' },
      { q: 'Stressfactoren',
        a: 'Verhuisd in januari 2024, nieuwe stal en nieuwe paddock-genoten.', kind: 'flag' },
      { q: 'Gedragsveranderingen recent', a: 'Iets prikkelbaarder bij singelen sinds april.', kind: 'flag' },
      { q: 'Allergieën', a: 'Geen bekend' },
      { q: 'Ervaring holistisch', a: 'Osteopaat 2× in 2024, voor stijfheid lendenen' },
      { q: 'Wat vindt ze leuk?', a: 'Bos-uitstapjes, krabben op de schoft, samen met haar paddock-vriendin Lotte' },
    ],
    geschiedenis: [
      { q: 'Moeder gevoerd / gehuisvest', a: 'Fokstal in Friesland. Traditioneel, box met paddock, droog hooi.' },
      { q: 'Eerste maanden', a: 'Geboren in mei, eerste 4 maanden bij moeder op paddock. Daarna kuddehuisvesting.' },
      { q: 'Moeder metabolisch?',
        a: 'Volgens fokker had moeder lichte mestwater-klachten in voorjaar', kind: 'flag' },
      { q: 'Spenen tot inrijden, huisvesting', a: 'Kuddehuisvesting buiten, 4 jaar lang' },
      { q: 'Spenen tot inrijden, voeding', a: 'Onverpakt hooi, paardenbrok van de fokker (merk onbekend)' },
      { q: 'Spenen tot inrijden, symptomen', a: 'Lichte koliek op 3-jarige leeftijd (najaar). Verder niets.', kind: 'flag' },
      { q: 'Medische geschiedenis, chronologisch',
        a: '2020: lichte koliek (3 jr) na voerwissel; conservatief behandeld.\n' +
           '2023: opnieuw lichte kolieken (2x in najaar) na overgang nieuwe voerleverancier.\n' +
           '2025: 6-weekse kuur antihistaminicum voor jeuk; geen langdurig effect.\n' +
           '2026 (voorjaar): jeuk komt terug, in mei erger dan vorig jaar.', kind: 'flag' },
    ],
    medisch: [
      { q: 'Vaccinaties', a: 'Tetanus + influenza, jaarlijks' },
      { q: 'Laatste vacc · volgende', a: '16 maart 2026 · volgende maart 2027' },
      { q: 'Laatste ontworming · middel · volgende',
        a: 'Oktober 2025 · Equimax · planning oktober 2026 (op basis van mestonderzoek)' },
      { q: 'Tandarts frequentie · laatst · volgende', a: 'Jaarlijks · feb 2026 · feb 2027' },
      { q: 'Tandarts methode', a: 'Elektrisch met verdoving' },
      { q: 'Tandarts bijzonderheden', a: 'Wisseling melktanden ok verlopen.' },
      { q: 'Hoefsmid frequentie', a: 'Elke 6 weken, barefoot trimmer' },
      { q: 'Voeten bijzonderheden', a: 'Geen' },
      { q: 'IJzers?', a: 'Nee' },
      { q: 'Zadelmaker', a: 'Jaarlijks; laatst sept 2025' },
      { q: 'Zadel bijzonderheden in verleden', a: 'Geen' },

      { q: 'IR aangetoond / vermoeden?', a: 'Nee' },
      { q: 'EMS aangetoond?', a: 'Nee' },
      { q: 'KPU aangetoond?', a: 'Nee' },
      { q: 'Hoefbevangenheid?', a: 'Nooit' },

      { q: 'Maag heeft ondersteuning nodig?', a: 'Ja, mild', kind: 'protocol',
        meta: 'Fase 0 activeren · 6 weken vóór fase 1' },
      { q: 'Maagsymptomen', a: 'Soppen van hooi af en toe · gespannen bij eten', kind: 'flag' },
      { q: 'Eerder gastro-medicatie?', a: 'Nee' },

      { q: 'Gasserig / opgeblazen?', a: 'Soms', kind: 'protocol',
        meta: 'Waarschuwing: gasserigheid kan erger door gras, let op gaskoliek' },
      { q: 'Probiotica-geschiedenis?', a: 'Nee' },
      { q: 'Medicatie nu?', a: 'Geen' },
      { q: 'Medicatie laatste jaar?', a: '6-weekse kuur antihistaminicum (zomer 2025)', kind: 'flag' },
    ],
    voer: [
      { q: 'Hooi verpakking', a: 'Onverpakt (touwtjes)' },
      { q: 'Hooi omschrijving',
        a: 'Wisselend per baal, soms grof + stengelig, soms rijker · groenig · tweede snede meestal', kind: 'flag' },
      { q: 'Kwaliteit & geur', a: 'Ruikt lekker · soms iets stoffig' },
      { q: 'Hooi-analyse', a: 'Niet beschikbaar' },
      { q: 'Constant of wisselend?', a: 'Wisselt per baal', kind: 'flag' },
      { q: 'Herkomst', a: 'Ingekocht via stal' },
      { q: 'Aanbod',
        a: 'Los op grond + 1 slowfeeder \'s nachts (maasbreedte 4 cm). 3 voerplekken. Continu \'s nachts.' },
      { q: 'Pauze incl. nacht', a: '3–4 uur (slowfeeder leeg rond 04:00)', kind: 'protocol',
        meta: 'Aanbeveling: extra slowfeeder ophangen' },
      { q: 'Voerbeurten', a: '3 (ochtend, middag, avond) + nacht-slowfeeder' },
      { q: 'Eerst ruwvoer of krachtvoer?', a: 'Eerst ruwvoer' },
      { q: 'Kg ruwvoer per dag', a: '~9 kg verdeeld over de dag' },
      { q: 'Voordroog ooit?', a: 'Ja, vorige stal, gestopt sinds januari 2024' },
      { q: 'Stro?', a: 'Vlas, eet ze niet' },
      { q: 'Recent gewisseld?', a: 'Nee, sinds 2 jaar zelfde brok' },
      { q: 'Krachtvoer',
        a: 'Pavo Slobber · ½ schep \'s avonds (~250 gr).\n' +
           'Geen muesli of granen.' },
      { q: 'Bijvoer 5 jaar terug', a: 'Bij vorige eigenaar: Pavo Care4Life muesli. Sinds 2022 alleen Slobber.' },
      { q: 'Balancer / mineralen', a: 'Geen specifieke balancer.', kind: 'flag' },
      { q: 'Mineralen toegang', a: 'Himalaya zoutsteen' },
      { q: 'Snacks?', a: 'Ja, wortel + appel, 1× per dag handvol', kind: 'flag' },
      { q: 'Huidige extra\'s',
        a: '· Magnesium 25 gr/dag (sinds feb 2026, voor "rust")\n· Zeewier 5 gr/dag (sinds feb 2026)',
        kind: 'flag' },
      { q: 'Laatste 5 jaar gehad',
        a: '· 2024: 3 mnd glucosamine (gewricht-onderhoud)\n' +
           '· 2025: 6 wk antihistaminicum (DA)\n' +
           '· 2025–nu: magnesium + zeewier' },
      { q: 'Foto hooi', a: '', kind: 'photo' },
    ],
    water: [
      { q: 'Water-type', a: 'Leidingwater' },
      { q: 'Aanbod', a: 'Automatische drinkbak (laag, in box)' },
      { q: 'Hele dag toegang?', a: 'Ja' },
      { q: 'Kwaliteit', a: 'Soms aanslag, niet wekelijks schoongemaakt door stal', kind: 'flag' },
      { q: 'Vochtinname', a: 'Lijkt minder sinds verhuizing', kind: 'flag' },
      { q: 'Urine', a: 'Helder, lichtgeel, normaal volume' },
      { q: 'Mest frequentie', a: '6–10× per dag' },
      { q: 'Mest geur', a: 'Iets penetrant', kind: 'flag' },
      { q: 'Mest kleur', a: 'Bruingroen' },
      { q: 'Mest vorm', a: 'Wisselend, soms wat losser', kind: 'protocol',
        meta: 'Fase 1b kandidaat als nog steeds bij eval week 6' },
      { q: 'Foto mest (24u)', a: '', kind: 'photo' },
    ],
    huisvesting: [
      { q: 'Huisvesting zomer',
        a: 'Individuele box \'s nachts (12u). Overdag paddock paradise met 4 paarden, 6-8 uur, 2-3 uur gras.' },
      { q: 'Huisvesting winter',
        a: '\'s Nachts box, overdag paddock zonder gras (zandbodem, hooi-stations).' },
      { q: 'Stal-uren per dag', a: '12–18 uur', kind: 'flag' },
      { q: 'Niet op stal, hoe?', a: 'Zandpaddock + weiland (zomer)' },
      { q: 'Bodembedekking stal', a: 'Vlas + rubberen matten' },
      { q: 'Gras, maanden', a: 'april t/m oktober (7 maanden)' },
      { q: 'Gras, uren per dag', a: '3–4 uur' },
      { q: 'Ooit op gras? (vit. E)', a: 'Ja, regelmatig' },
      { q: 'Mestwater bij transitie',
        a: 'Ja, begin · was 2 weken licht mestwater', kind: 'flag' },
      { q: 'Type grasland', a: 'Paardengras · niet bemest (volgens beheerder)' },
      { q: 'Bomen / struiken',
        a: 'Eiken aan de rand van weiland. Niet bereikbaar door afrastering.', kind: 'flag' },
      { q: 'Sociale groep', a: '4 paarden · ruinen en merries gemixt · 4–18 jaar' },
      { q: 'Sociale interactie', a: 'Rustig. Nova zit middenin de hiërarchie.' },
      { q: 'Maatjes', a: 'Lotte (Welsh-mix merrie, 11 jaar)' },
      { q: 'Hiërarchie', a: 'Midden' },
      { q: 'Genoeg ruimte om af te zonderen?', a: 'Ja' },
      { q: 'Pestgedrag / stress?', a: 'Geen pesten. Wel gespannen bij voertijd.', kind: 'flag' },
      { q: 'Andere diersoorten', a: 'Schapen op aangrenzend weiland' },
      { q: 'Foto stal', a: '', kind: 'photo' },
    ],
    gedrag: [
      { q: 'Beweging arbeid', a: '4–5× per week ~45 min, longeren of dressuur' },
      { q: 'Discipline', a: 'Dressuur (recreatief), af en toe buitenrit' },
      { q: 'Training frequentie', a: '4–5× per week' },
      { q: 'Intensiteit', a: 'Licht tot matig' },
      { q: 'Knelpunten tijdens training',
        a: 'Rechts iets stijver dan links. Boos met aansingelen, vooral rechts.', kind: 'flag' },
      { q: 'Conditie volgens eigenaar', a: 'Goed, alleen iets te zwaar. Energie wisselend.' },
      { q: 'Stress-symptomen', a: 'Weven bij voertijd, sinds verhuizing', kind: 'flag' },
      { q: 'Headshaking?', a: 'Nee' },
      { q: 'Agressief / beschermend?', a: 'Nee' },
      { q: 'Typisch gedrag',
        a: 'Soppen van hooi · boos bij aansingelen · soms kieskeurig met voer', kind: 'flag' },
      { q: 'Omgang soortgenoten', a: 'Vriendelijk · iets terughoudend met onbekenden' },
      { q: 'Stress-triggers', a: 'Vrachtwagens, harde geluiden, onbekende paarden' },
      { q: 'Karakter', a: 'Zachtaardig maar gevoelig. Wil graag pleasen. Eigenwijs als ze moe is.' },
    ],
    fysiek: [
      { q: 'Huid',
        a: 'Plekken zonder haar bij manen + staartwortel · lichte schilfers · rood waar gekrabd', kind: 'flag' },
      { q: 'Vacht / haar',
        a: 'Glanzend op romp · dof bij manen waar gekrabd · wisselt sterk met seizoen' },
      { q: 'Hoeven', a: 'Sterk · gelijke afslijting' },
      { q: 'Slijmvliezen', a: 'Roze · normaal vochtig' },
      { q: 'Ademhaling rust', a: 'Rustig' },
      { q: 'Bouw', a: 'Stevig · kort · gespierd' },
      { q: 'Bespiering L–R', a: 'Links iets meer dan rechts', kind: 'flag' },
      { q: 'Iets fysieks anders',
        a: 'Soms aanvoelen van warm onderbeen rechts achter na training, verdwijnt snel.' },
      { q: 'Foto zijaanzicht', a: '', kind: 'photo' },
      { q: 'Foto vooraanzicht', a: '', kind: 'photo' },
      { q: 'Foto achteraanzicht', a: '', kind: 'photo' },
      { q: 'Foto klacht (manen + staart)', a: '', kind: 'photo' },
      { q: 'Foto 4× hoeven', a: '', kind: 'photo' },
      { q: 'Foto slijmvlies', a: '', kind: 'photo' },
    ],
  },
};

/* ====================================================================
   CONCEPT_PROTOCOL, wat het systeem AUTOMATISCH klaarzet voor Nova
   Toont expliciet welke intake-antwoorden welke aanpassing triggerden.
   ==================================================================== */
const CONCEPT_PROTOCOL = {
  horse: 'Nova',
  generated: 'op basis van intake INT-2026-051 + standaard-protocol Darmrevalidatie',
  template: 'Darmrevalidatie (Shelley · mei 2026)',
  doseergewicht: { template: 600, intake: 540, factor: 0.9 },

  summary:
    'Aanhoudende jeuk + voorjaar-piek, in combinatie met wisselende ruwvoerkwaliteit, eerdere lichte kolieken, soms losse mest en stress sinds verhuizing wijzen op een verstoorde darmflora. De huid is het signaal, niet de oorzaak.',
  cause:
    'Verstoorde darmflora door wisselend rijk ruwvoer + stress sinds verhuizing. Histamineproductie verhoogd; manifesteert als jeuk in voorjaar (gras-piek).',
  confidence: 'hoog',

  /* Veiligheidschecks: status = ok | warn | block | modify */
  veiligheidsChecks: [
    { id: 'sv1', check: 'Niet drachtig / lacterend',     status: 'ok',
      bewijs: 'paard.drachtig = nee' },
    { id: 'sv2', check: 'Geen acute klachten',            status: 'ok',
      bewijs: 'klacht.acuut = geen' },
    { id: 'sv3', check: 'Geen reguliere medicatie',       status: 'ok',
      bewijs: 'medisch.medicatie-nu = leeg' },
    { id: 'sv4', check: 'KPU niet aangetoond/vermoed',    status: 'ok',
      bewijs: 'medisch.kpu-status = nee' },
    { id: 'sv5', check: 'IR / EMS niet aangetoond/vermoed', status: 'ok',
      bewijs: 'medisch.ir-status = nee, medisch.ems-status = nee' },
    { id: 'sv6', check: 'Hoefbevangenheid: nooit',         status: 'ok',
      bewijs: 'medisch.hoefbevangenheid = nooit' },
    { id: 'sv7', check: 'Komt regelmatig op gras',         status: 'ok',
      bewijs: 'huisvesting.nooit-gras = ja, regelmatig, vit. E niet nodig' },
    { id: 'sv8', check: 'Geen probiotica-geschiedenis',    status: 'ok',
      bewijs: 'medisch.probiotica-geschiedenis = nee, Silsterk niet nodig' },
    { id: 'sv9', check: 'Maag heeft ondersteuning nodig',  status: 'modify',
      bewijs: 'medisch.maag-ondersteuning = ja, mild → Fase 0 geactiveerd (6 wk)' },
    { id: 'sv10', check: 'Soms gasserig',                  status: 'warn',
      bewijs: 'medisch.gasserig = soms → waarschuwing + extra ruwvoer-tip' },
  ],

  /* Auto-applied modifications, welke intake-antwoord wat triggerde */
  modifications: [
    { id: 'mod1', trigger: 'medisch.maag-ondersteuning = ja, mild',
      effect: 'Stap 1 (Maag tot rust) geactiveerd · 6 weken vóór Stap 2' },
    { id: 'mod2', trigger: 'voer.hooi-pauze-incl-nacht = 3–4 u',
      effect: 'Aanbeveling: extra slowfeeder ophangen (niet verplicht)' },
    { id: 'mod3', trigger: 'paard.gewicht = 540 kg',
      effect: 'Alle doseringen × 0.9 (basis = 600 kg)' },
    { id: 'mod4', trigger: 'medisch.gasserig = soms',
      effect: 'Waarschuwing tonen bij start Stap 2 · monitoren of Colobalance nodig is' },
    { id: 'mod5', trigger: 'water.mest-vorm = wisselend / soms losser',
      effect: 'Mestwater-signaal aanzetten · Stap 2+ kandidaat als nog bij wk 6 eval' },
  ],

  phases: [
    {
      id: 'voorber',
      name: 'Startweek · de basis op orde',
      duration: '1 week · vóór Stap 1',
      state: 'todo',
      items: [
        { id: 1, t: 'Extra slowfeeder ophangen, geen ruwvoer-pauze >2 uur', tag: 'management' },
        { id: 2, t: 'Magnesium + zeewier pauzeren, eerst protocol-basis stabilisren', tag: 'supplementen' },
        { id: 3, t: 'Snoep, fruit, brood STOP gedurende heel protocol (incl. wortel, appel)', tag: 'voer' },
        { id: 4, t: 'Foto van manen + mest elke 3 dagen', tag: 'observatie' },
        { id: 5, t: 'Stal vragen: consistentie ruwvoerkwaliteit per baal, log per dag', tag: 'management' },
      ],
    },
    {
      id: 'f0',
      name: 'Stap 1 · Maag tot rust',
      duration: '6 weken',
      state: 'todo',
      activated: 'maag-ondersteuning = ja, mild',
      items: [
        { id: 10, t: 'Gekookt bio lijnzaad · 72-90 gr lauwwarm · 2× daags', tag: 'voer',
          note: 'standaard 80-100 gr × 0.9 = 72-90 gr' },
        { id: 11, t: 'Kaasjeskruid + Heemstwortel · 14 gr per kruid · 2× daags · weken in heet water',
          tag: 'kruid', note: 'standaard 15 gr × 0.9' },
        { id: 12, t: 'Mest- en huidobservatie elke 3 dagen', tag: 'observatie' },
      ],
    },
    {
      id: 'f1',
      name: 'Stap 2 · Darmflora herstellen',
      duration: '6 weken',
      state: 'todo',
      starts: 'na 2 volledige weken Stap 1',
      items: [
        { id: 20, t: 'Salie · 6–7 verse bladeren of 9 gr gedroogd · 1× daags · STOP na 4 wk', tag: 'kruid' },
        { id: 21, t: 'Lapachoschors · 9–18 gr · 1× daags', tag: 'kruid' },
        { id: 22, t: 'Smalle weegbree · 27–45 gr · 1× daags', tag: 'kruid' },
        { id: 23, t: 'Paardenbloemblad · 27–45 gr · 1× daags', tag: 'kruid' },
        { id: 24, t: 'Zoethout extract · 7 gr · 1× daags · WK 1, 2, 5, 6', tag: 'kruid',
          note: 'Geen risico hoefbevangenheid, staat aan' },
        { id: 25, t: 'Krachtvoer Pavo Slobber: tijdelijk weglaten OF kleiner', tag: 'voer' },
        { id: 26, t: 'Wekelijkse foto van mest + observatie via app', tag: 'observatie' },
      ],
      noten: [
        'Zoethout STAAT AAN: hoefbevangenheid = nooit → geen risico',
        'Colobalance NIET nodig nu, gasserig = "soms"; alleen toevoegen als wk 1-2 erger wordt',
      ],
    },
    {
      id: 'eval-f1',
      name: 'Tussenevaluatie · we kijken samen',
      duration: 'begin week 6',
      state: 'todo',
      kind: 'evaluation',
      items: [
        { id: 30, t: 'Ontlasting stabiel?', tag: 'observatie' },
        { id: 31, t: 'Nog gasserig / opgeblazen?', tag: 'observatie' },
        { id: 32, t: 'Mestwater aanwezig?', tag: 'observatie' },
        { id: 33, t: 'Reageert op voerveranderingen?', tag: 'observatie' },
        { id: 34, t: 'Jeuk verminderd?', tag: 'observatie' },
      ],
    },
    {
      id: 'f2',
      name: 'Stap 3 · Lever en nieren opschonen',
      duration: '12 weken · oneven/even ritme',
      state: 'todo',
      starts: 'als darmen stabiel zijn na Stap 2 (of via Stap 2+)',
      ritmeOneven: {
        label: 'Oneven weken (1, 3, 5, 7, 9, 11)',
        items: [
          { id: 40, t: 'Ontslakkingskruiden · 27–45 gr · 1× daags', tag: 'kruid' },
          { id: 41, t: 'Bio Spirulina · 27 gr · 1× daags', tag: 'supplementen' },
          { id: 42, t: 'Vitamine B6 · 5× 21 mg · 1× daags', tag: 'supplementen' },
          { id: 43, t: 'Vitamine B12 · 1× 25 mcg · 1× daags', tag: 'supplementen' },
        ],
      },
      ritmeEven: {
        label: 'Even weken (2, 4, 6, 8, 10, 12), stopweken',
        items: [
          { id: 50, t: 'Zeoliet · ~36 gr · 1× daags · APART van andere voeding', tag: 'supplementen',
            warning: 'Nooit tegelijk met vitamines geven' },
        ],
      },
    },
  ],

  signalen: [
    { trigger: '2 wk op rij mest zacht of diarree',     level: 'rood',   actie: 'Shelley meldt zich binnen 24u' },
    { trigger: '2 wk op rij gasserig of opgeblazen',    level: 'oranje', actie: 'Shelley krijgt melding' },
    { trigger: 'Mestwater aanwezig na week 4',          level: 'oranje', actie: 'Shelley krijgt melding' },
    { trigger: 'Marit antwoordt "bezorgd"',             level: 'oranje', actie: 'Shelley reageert binnen 24u' },
    { trigger: '2 wk to do\'s niet uitgevoerd',         level: 'geel',   actie: 'Auto-reminder · dashboard-flag' },
  ],

  verwachtingen: [
    { wanneer: 'Week 1–2', bericht: 'Nog geen grote verandering. Lichaam went aan de ondersteuning.' },
    { wanneer: 'Week 3–4', bericht: 'Mest wordt vaster en consistenter. Minder gasrijkheid.' },
    { wanneer: 'Week 5–6', bericht: 'Stabielere ontlasting. Minder mestwater. Rustiger karakter.' },
    { wanneer: 'Na Stap 3',bericht: 'Duurzaam stabiele darmen. Minder gevoelig voor voerveranderingen en stress.' },
  ],

  shelleysNote:
    'Marit, fijn dat je hier bent. Ik denk dat we hier echt iets gaan zien. Belangrijk: niet schrikken als de jeuk in week 2 even erger lijkt, dat is detox. Stuur me elke 3 dagen een foto van de manen, dan houden we het samen in de gaten.',
};

/* ====================================================================
   CLIENT_MONITORING, lopende protocollen voor de monitoring-view
   ==================================================================== */
const CLIENT_MONITORING = [
  { id: 'C-019', horse: 'Storm',  owner: 'Bas H.',   av: 'B', phase: 'Stap 2 · Darmen',  week: 'wk 3 / 6',  pct: 50,
    checkin: 'gisteren', mood: 'goed', signal: null,
    note: 'Mest is iets vaster geworden, jeuk neemt af.' },
  { id: 'C-018', horse: 'Aster',  owner: 'Elise L.', av: 'E', phase: 'Stap 2 · Darmen',  week: 'wk 4 / 6',  pct: 66,
    checkin: 'eergisteren', mood: 'ok', signal: 'oranje',
    note: '2 wk gasserig, Colobalance toevoegen?' },
  { id: 'C-016', horse: 'Bjorn',  owner: 'Sanne K.', av: 'S', phase: 'Stap 3 · Lever/nier', week: 'wk 7 / 12', pct: 58,
    checkin: 'vandaag',   mood: 'top', signal: null,
    note: 'Manen volledig dichtgegroeid. Mest stabiel.' },
  { id: 'C-014', horse: 'Faro',   owner: 'Tessa W.', av: 'T', phase: 'Stap 2+ · Extra rust', week: 'wk 2 / 4', pct: 50,
    checkin: '3 dgn',     mood: 'minder', signal: 'oranje',
    note: 'Mestwater hardnekkig, overweeg Aardpeerpellets.' },
  { id: 'C-013', horse: 'Luna',   owner: 'Iris D.',  av: 'I', phase: 'Stap 1 · Maag',     week: 'wk 5 / 6',  pct: 83,
    checkin: 'vandaag',   mood: 'goed', signal: null,
    note: 'Klaar voor Stap 2 over 1 week.' },
  { id: 'C-011', horse: 'Bjorn II', owner: 'Ron K.', av: 'R', phase: 'Startweek',          week: 'dag 4 / 7', pct: 57,
    checkin: 'gisteren',  mood: 'ok',   signal: null,
    note: 'Eerste dagen vlot, slowfeeder hangt.' },
  { id: 'C-009', horse: 'Eclipse', owner: 'Joris V.', av: 'J', phase: 'Stap 3 · Lever/nier', week: 'wk 11 / 12', pct: 92,
    checkin: 'vandaag',   mood: 'top',  signal: null,
    note: 'Eindevaluatie staat klaar.' },
  { id: 'C-006', horse: 'Iris',   owner: 'Femke S.', av: 'F', phase: 'Stap 2 · Darmen',  week: 'wk 1 / 6',  pct: 16,
    checkin: '5 dgn',     mood: '?',    signal: 'geel',
    note: 'Geen check-in 5 dagen, auto-reminder gestuurd.' },
];

/* ====================================================================
   WEEK 6 EVAL, een lopende evaluatie voor de eval-view
   ==================================================================== */
const WEEK6_EVAL = {
  horse: 'Storm',
  owner: 'Bas Hoogendijk',
  id: 'C-019',
  evalDate: '14 mei 2026',
  phase: 'Stap 2 · Darmflora herstellen',
  weeks: 6,
  answers: [
    { q: 'Is de ontlasting stabiel of wisselt het per dag / week?',
      a: 'Stabiel sinds wk 4. Geen mestwater meer.', state: 'ok' },
    { q: 'Is je paard nog (wel eens) gasserig?',
      a: 'Nee, sinds wk 3 niet meer opgemerkt.', state: 'ok' },
    { q: 'Is je paard nog (wel eens) opgeblazen?',
      a: 'Nee.', state: 'ok' },
    { q: 'Is er nog (subklinisch) mestwater?',
      a: 'Niet sinds wk 4.', state: 'ok' },
    { q: 'Is er (wel eens) diarree?',
      a: 'Nee.', state: 'ok' },
    { q: 'Triggert je paard op (voer)veranderingen?',
      a: 'Mest werd 1 dag iets losser na overgang naar nieuwe baal, kwam volgende dag terug.', state: 'mild' },
  ],
  observaties: [
    { wk: 1, mest: 'B-', jeuk: '8/10', energie: '6/10', notes: 'Eerste dagen gespannen, slowfeeder hing nog te hoog' },
    { wk: 2, mest: 'B',  jeuk: '8/10', energie: '6/10', notes: 'Jeuk iets erger - detox?' },
    { wk: 3, mest: 'B',  jeuk: '7/10', energie: '7/10', notes: 'Mest beter, jeuk neemt af' },
    { wk: 4, mest: 'B+', jeuk: '5/10', energie: '7/10', notes: 'Manen voelen minder schubbig' },
    { wk: 5, mest: 'B+', jeuk: '4/10', energie: '8/10', notes: 'Krabben minder vaak' },
    { wk: 6, mest: 'A-', jeuk: '3/10', energie: '8/10', notes: 'Bijna geen jeuk meer' },
  ],
  /* Auto-decision */
  decision: 'Door naar Stap 3 · Lever & nieren',
  reasoning: '5/6 antwoorden stabiel, 1 mild. Eindstaat: stabiel, Stap 2+ niet nodig.',
};

/* ---- Inbox: list of intakes pending therapist review ---- */
const INTAKE_INBOX = [
  { id: 'INT-2026-051', horse: 'Nova',  owner: 'Marit van der Berg', submitted: 'vandaag · 21:48',
    pakket: 'Herstelplan', status: 'nieuw',         flags: 11, focus: ['jeuk', 'darmen'], av: 'M' },
  { id: 'INT-2026-050', horse: 'Storm', owner: 'Bas Hoogendijk',     submitted: 'gisteren · 16:12',
    pakket: 'Herstelplan', status: 'concept-klaar', flags: 5,  focus: ['staakgedrag'], av: 'B' },
  { id: 'INT-2026-048', horse: 'Aster', owner: 'Elise van Loon',     submitted: '2 dagen · 09:30',
    pakket: 'Herstelplan', status: 'concept-klaar', flags: 2,  focus: ['darmen'], av: 'E' },
  { id: 'INT-2026-047', horse: 'Bjorn', owner: 'Sanne K.',           submitted: '3 dagen · 14:20',
    pakket: 'Herstelplan', status: 'gepubliceerd',  flags: 1,  focus: ['hoefbevangenheid'], av: 'S' },
  { id: 'INT-2026-045', horse: 'Faro',  owner: 'Tessa de Wit',       submitted: '5 dagen',
    pakket: 'Herstelplan', status: 'gepubliceerd',  flags: 0,  focus: ['jeuk'], av: 'T' },
];

window.INTAKE_SECTIONS    = INTAKE_SECTIONS;
window.FILLED_INTAKE      = FILLED_INTAKE;
window.CONCEPT_PROTOCOL   = CONCEPT_PROTOCOL;
window.CLIENT_MONITORING  = CLIENT_MONITORING;
window.WEEK6_EVAL         = WEEK6_EVAL;
window.INTAKE_INBOX       = INTAKE_INBOX;

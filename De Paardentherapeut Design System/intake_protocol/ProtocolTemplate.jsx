/* global window */
// ProtocolTemplate.jsx — het standaard Darmrevalidatie-protocol.
// Bron: Shelley's Notion-document "Protocol-Darmproblemen" (versie mei 2026).
// Dit is wat het systeem als CONCEPT klaarzet — Shelley past aan per paard.

const PROTOCOL_DARMEN = {
  id: 'darmen-standaard',
  naam: 'Darmrevalidatie · standaard',
  fokus: ['darmen', 'jeuk', 'mestwater', 'huid'],
  totaalDuur: '6 + 6–10 + 12 weken (max ~6 maanden)',
  basedOn: 'Shelley\'s Protocol-Darmproblemen (versie mei 2026)',

  /* ====================================================================
     VEILIGHEIDSCHECKS — afgewogen bij intake; protocol pas vrij bij groen.
     Level: block = stop · warn = mag starten · modify = pas inhoud aan
     ==================================================================== */
  veiligheidsChecks: [
    { als: 'Paard is drachtig of lacterend',
      dan: 'Protocol blokkeren',
      melding: 'Wacht tot dracht- of lactatieperiode voorbij is.',
      level: 'block' },
    { als: 'Acute klacht: koorts, ernstige kreupelheid, koliek',
      dan: 'Protocol blokkeren',
      melding: 'Los de acute klacht eerst op. Herstart daarna.',
      level: 'block' },
    { als: 'Hoefbevangenheid acuut',
      dan: 'Protocol blokkeren',
      melding: 'Los acute hoefbevangenheid eerst op.',
      level: 'block' },
    { als: 'Paard gebruikt reguliere medicatie',
      dan: 'Waarschuwing tonen · protocol mag starten',
      level: 'warn' },
    { als: 'KPU aangetoond',
      dan: 'Losse vit. B6, B12 en MSM weglaten in Stap 3 · HeparKPU/EquiKPU toevoegen',
      level: 'modify' },
    { als: 'IR of EMS aangetoond',
      dan: 'PankrEMS toevoegen vanaf week 4 Stap 2 · psyllium ipv lijnzaad in Stap 1',
      level: 'modify' },
    { als: 'IR vermoedelijk',
      dan: 'Schüsslerzouten nr. 10 + nr. 27 toevoegen vanaf week 4 Stap 2',
      level: 'modify' },
    { als: 'Hoefbevangenheid in verleden of risico',
      dan: 'Zoethout extract weglaten uit Stap 2',
      level: 'modify' },
    { als: 'Jarenlang probiotica / gist gehad',
      dan: 'Silsterk toevoegen: 7 dagen vóór start Stap 2',
      level: 'modify' },
    { als: 'Paard is gasserig',
      dan: 'Waarschuwing tonen + Colobalance in Stap 2',
      melding: 'Gasserigheid kan erger worden door gras. Let op kans op gaskoliek.',
      level: 'warn' },
    { als: 'Komt nooit op gras (ook niet 2u/dag in zomer)',
      dan: 'Natuurlijke vit. E geven (6–8 wk · zonder selenium · Pharmahorse RRR-Complex aanbevolen)',
      level: 'modify' },
    { als: 'Komt nooit op gras (vit. E toegevoegd)',
      dan: 'Losse MSM weglaten uit protocol',
      level: 'modify' },
  ],

  /* ====================================================================
     BASIS-REGELS — gelden altijd, voor elk paard, gedurende het protocol.
     ==================================================================== */
  basis: [
    { regel: 'Ruwvoer',         waarde: '2–3 kg per 100 kg lichaamsgewicht · onverpakt hooi uit touwtjes' },
    { regel: 'Vastperiodes',    waarde: 'Maximaal 2 uur — liever geen' },
    { regel: 'Zwarte lijst',    waarde: 'Verboden tijdens protocol (zie lijst hieronder)' },
    { regel: 'Snoep / fruit / brood', waarde: 'Verboden tijdens protocol' },
    { regel: 'Dosering starten',waarde: 'Altijd halve dosering · in 10–14 dagen opbouwen naar volle dosis' },
    { regel: 'Doseergewicht',   waarde: 'Berekening op basis van 600 kg — app herberekent op echt gewicht uit intake' },
    { regel: 'Minimum uitvoering', waarde: 'Min. 4 dagen/week als eigenaar niet elke dag aanwezig is' },
  ],

  /* ====================================================================
     ZWARTE LIJST — wat sowieso uit het voer moet tijdens het protocol.
     ==================================================================== */
  zwarteLijst: {
    voeding: [
      'Brok / muesli met granen (haver, gerst, maïs, tarwe)',
      'Bietenpulp met melasse',
      'Soja-producten',
      'Zonnebloemolie & soja-olie',
      'Geconserveerd ruwvoer (kuil/voordroog) — uitzondering bij medisch advies',
      'Industriële paardensnoepjes',
      'Brood, koek, suikerwerk',
    ],
    supplementen: [
      'Probiotica & gist (yeast) — tijdens lopend protocol',
      'Synthetische multi-vitamines',
      'Selenium-houdende supplementen (tenzij bloedonderzoek bewijst tekort)',
      'IJzer-supplementen (tenzij dierenarts adviseert)',
    ],
    snacks: [
      'Wortels, appels, peren (suikers)',
      'Banaan, druif, mango',
      'Mueslirepen, brood',
      'Suikerrijke paardenkoekjes',
    ],
  },

  /* ====================================================================
     FASEN
     ==================================================================== */
  fases: [
    {
      id: 'f0',
      naam: 'Stap 1 · Maag tot rust',
      duur: '6 weken',
      optional: true,
      activeWhen: 'Intake: maag heeft ondersteuning nodig = ja',
      goal: 'Maagslijmvlies beschermen en herstellen vóór we de darmen aanpakken',
      items: [
        { nr: '1.1', t: 'Gekookt bio lijnzaad',
          dosis: '80–100 gr lauwwarm over geweekte bijvoeding',
          freq: '2× daags (of 1× zelfde hoeveelheid)',
          waarom: 'Beschermt en herstelt maagslijmvlies',
          alternateIf: 'IR vermoedelijk → vervang door psylliumzaad (zie 1.2)' },
        { nr: '1.2', t: 'Psylliumzaad (alleen bij IR)',
          dosis: '175–200 gr psyllium ZAAD — 5–10 min meeweken in geweekte bijvoeding',
          freq: '2× daags',
          waarom: 'Alternatief voor lijnzaad bij IR paarden',
          conditional: 'Alleen bij IR — duur 8 weken (2 wk langer)' },
        { nr: '3', t: 'Kaasjeskruid + Heemstwortel',
          dosis: '15 gr per kruid · ca. 200 ml heet water · 20–30 min weken · regelmatig roeren',
          freq: '2× daags',
          waarom: 'Ontstekingsremmend & verzachtend op spijsvertering' },
      ],
      regels: [
        { als: 'Maagprobleem ernstig / langdurig', dan: 'Gastercare toevoegen — 2× daags · 8 weken' },
        { als: '>8 weken zaden gegeven',           dan: 'Altijd stoppen met zaden' },
      ],
    },

    {
      id: 'f1',
      naam: 'Stap 2 · Darmflora herstellen',
      duur: '6 weken',
      activeWhen: 'Direct na intake-akkoord OF na 2 wk Stap 1',
      goal: 'Darmflora opnieuw opbouwen — kruiden, slijmvliesondersteuning, ontstekingsremmend',
      items: [
        { nr: '1', t: 'Salie',           dosis: '6–7 verse bladeren of 10 gr gedroogd', freq: '1× daags',
          waarom: 'Ontstekings- en infectiewerend, krampwerend, windverdrijvend bij darmgistingen',
          stopAfter: 'Na 4 weken stoppen' },
        { nr: '2', t: 'Lapachoschors',   dosis: '10–20 gr',  freq: '1× daags',
          waarom: 'Antimicrobieel · ondersteunt gezonde darmflora' },
        { nr: '3', t: 'Smalle weegbree', dosis: '30–50 gr',  freq: '1× daags',
          waarom: 'Kalmeert darmwand · herstelt slijmvlies' },
        { nr: '4', t: 'Paardenbloemblad',dosis: '30–50 gr',  freq: '1× daags',
          waarom: 'Ondersteunt lever en spijsvertering' },
      ],
      conditionalAddOns: [
        { als: 'Gasserig of opgeblazen',
          add: 'Colobalance', dosis: '50 gr', freq: '1× daags', duur: '14 dagen',
          extra: 'Instructie: absoluut onbeperkt ruwvoer, geen vastperiodes' },
        { als: 'Probiotica-geschiedenis',
          add: 'Silsterk', timing: '7 dagen vóór start Stap 2',
          dosis: 'dag 1+2: 2-3× 5 druppels · daarna 2-3× 10 druppels' },
        { als: 'Geen risico hoefbevangenheid · week 1, 2, 5, 6',
          add: 'Zoethout extract', dosis: '8 gr', freq: '1× daags' },
        { als: 'Week 4 bereikt · IR vermoedelijk',
          add: 'Schüsslerzouten nr. 10 + nr. 27',
          dosis: '3 tabletten per celzout', freq: '2× daags', duur: '8 weken' },
        { als: 'Week 4 bereikt · IR of EMS aangetoond',
          add: 'PankrEMS', dosis: '15 gr', freq: '1× daags', duur: 'minimaal 3 maanden' },
        { als: 'Week 4 bereikt · KPU aangetoond',
          add: 'HeparKPU forte of EquiKPU',
          dosis: 'leverancier-dosering', duur: '12–18 maanden' },
        { als: 'Komt nooit op gras',
          add: 'Natuurlijke vit. E (RRR-complex Pharmahorse, zónder selenium)',
          dosis: 'leverancier-dosering', duur: '6–8 weken',
          extra: 'Losse MSM weglaten als vit. E erbij komt' },
      ],
      stops: [
        'Salie na 4 weken — altijd stoppen',
        'Zwavel en zink na 8 weken — altijd stoppen',
        'Zoethout extract NIET in week 3 en 4',
      ],
      evaluatie: 'Begin week 6 — evaluatievragen sturen',
    },

    {
      id: 'eval-f1',
      naam: 'Tussenevaluatie · we kijken samen',
      duur: 'begin week 6',
      kind: 'evaluation',
      vragen: [
        'Is de ontlasting stabiel of wisselt het per dag / week?',
        'Is je paard nog (wel eens) gasserig?',
        'Is je paard nog (wel eens) opgeblazen?',
        'Is er nog (subklinisch) mestwater?',
        'Is er (wel eens) diarree?',
        'Triggert je paard op (voer)veranderingen?',
      ],
      uitkomsten: [
        { als: 'Alle antwoorden: darmen stabiel',         dan: 'Door naar Stap 3 · Lever en nieren' },
        { als: 'Eén of meer antwoorden: niet stabiel',    dan: 'Door naar Stap 2+ · Extra rust' },
        { als: 'Onduidelijk',                              dan: 'Shelley beoordeelt handmatig' },
      ],
    },

    {
      id: 'f1b',
      naam: 'Stap 2+ · Extra rust voor de darmen',
      duur: '4 weken',
      activeWhen: 'Eén of meer evaluatievragen niet stabiel',
      goal: 'Persistente darmonrust kalmeren · doorstroming verbeteren',
      items: [
        { nr: '1', t: 'Gemberwortelpoeder', dosis: '5–10 gr', freq: '1× daags',
          waarom: 'Verbetert spijsvertering · krampstillend · ontstekingswerend' },
        { nr: '2', t: 'Boswellia Serrata',  dosis: '5–10 gr', freq: '2× daags (of 1× zelfde dosis)',
          waarom: 'Remt slijmvliesontsteking · helpt bij krampen & gasvorming' },
        { nr: '3', t: 'Duizendblad',        dosis: '5–10 gr', freq: '1× daags',
          waarom: 'Goede doorstroming / regulatie in de darmen' },
      ],
      conditionalAddOns: [
        { als: '(Heel) veel mestwater',
          add: 'Aardpeerpellets (niet de stengels)',
          dosis: '50–100 gr door basisvoeding',
          duur: '2 weken' },
      ],
      evaluatie: 'Week 4 — evaluatieformulier sturen',
      uitkomsten: [
        { als: 'Stabiel',      dan: 'Door naar Stap 3 · Lever en nieren' },
        { als: 'Niet stabiel', dan: 'Stap 2 herhalen' },
      ],
    },

    {
      id: 'f2',
      naam: 'Stap 3 · Lever en nieren opschonen',
      duur: '12 weken (oneven/even ritme)',
      activeWhen: 'Darmen stabiel na Stap 2 of Stap 2+',
      goal: 'Lever- en nierwerking ondersteunen · ontgiften & remineraliseren',

      oneven: {
        label: 'Oneven weken — actief ondersteunen',
        items: [
          { nr: '1', t: 'Ontslakkingskruiden', dosis: '30–50 gr', freq: '1× daags',
            waarom: 'Lever- en nierondersteuning',
            link: 'dtails.nl · Ontslakkingskruiden' },
          { nr: '2', t: 'Bio Spirulina',       dosis: '30 gr',    freq: '1× daags',
            waarom: 'Ondersteunt ontgifting en immuunsysteem' },
          { nr: '3', t: 'Vitamine B6',         dosis: '5 pillen à 21 mg', freq: '1× daags',
            waarom: 'Ondersteunt leverwerking',
            skipIf: 'KPU = ja én HeparKPU/EquiKPU al actief' },
          { nr: '4', t: 'Vitamine B12',        dosis: '1 pil van 25 mcg', freq: '1× daags',
            waarom: 'Ondersteunt bloedaanmaak',
            skipIf: 'KPU = ja én HeparKPU/EquiKPU al actief' },
        ],
      },
      even: {
        label: 'Even weken — stopweek',
        items: [
          { nr: '1', t: 'Zeoliet', dosis: '~40 gr', freq: '1× daags · APART van andere voeding',
            waarom: 'Bindt afvalstoffen',
            warning: 'Nooit tegelijk met vitamines geven.' },
        ],
      },
      evaluatie: 'Week 12 — eindevaluatie',
    },
  ],

  /* ====================================================================
     SIGNAALMOMENTEN — automatic flags op Shelley's dashboard
     ==================================================================== */
  signalen: [
    { trigger: '2 weken op rij mest zacht of diarree',  level: 'rood',
      actie: 'Shelley ontvangt directe melding · binnen 24u reageren' },
    { trigger: '2 weken op rij gasserig of opgeblazen', level: 'oranje',
      actie: 'Shelley ontvangt melding' },
    { trigger: 'Mestwater aanwezig na week 4',          level: 'oranje',
      actie: 'Shelley ontvangt melding' },
    { trigger: 'Eigenaar antwoordt "bezorgd"',          level: 'oranje',
      actie: 'Shelley reageert binnen 24 uur' },
    { trigger: '2 weken to do\'s niet uitgevoerd',      level: 'geel',
      actie: 'Auto-reminder naar gebruiker · signaal in dashboard' },
  ],

  /* ====================================================================
     VERWACHTINGSMANAGEMENT — verschijnt als motivatie in de app
     ==================================================================== */
  verwachtingen: [
    { wanneer: 'Week 1–2', bericht: 'Nog geen grote verandering zichtbaar. Het lichaam went aan de nieuwe ondersteuning.' },
    { wanneer: 'Week 3–4', bericht: 'Mest wordt vaster en consistenter. Minder gasrijkheid.' },
    { wanneer: 'Week 5–6', bericht: 'Duidelijk stabielere ontlasting. Minder of geen mestwater. Paard rustiger van karakter.' },
    { wanneer: 'Na Stap 3',bericht: 'Duurzaam stabiele darmen. Minder gevoelig voor voerveranderingen en stress.' },
  ],
};

window.PROTOCOL_DARMEN = PROTOCOL_DARMEN;

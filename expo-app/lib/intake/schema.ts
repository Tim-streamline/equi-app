// Single source of truth for the Protocol Intake form ("Het Holistisch
// Herstelplan — intake"). Ported from
// "De Paardentherapeut Design System/intake_protocol/IntakeFormSchema.jsx"
// (field copy) and IntakeData.jsx (overview title/sub/icon/minutes metadata).
//
// The intake is filled in by the customer in-app after they buy a protocol
// package. Each section has a stable id used as the key inside the answers
// object; each field has a per-section id used as a sub-key.
//
// `flagIf`     — answer surfaces as an attention-point on Shelley's review.
// `criticalIf` — answer blocks protocol auto-start until reviewed manually.
// `showIf`     — field only renders when another field has a given value.
//                The special value 'any-checked' matches when the referenced
//                multi field has at least one non-"geen" option selected.

export type FieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'date'
  | 'radio'
  | 'multi'
  | 'photo'
  | 'file'
  | 'repeater'
  | 'sectionhead';

/** Either a single value or an array of accepted values. */
export type Trigger = string | string[];

export type ShowIf = Record<string, Trigger>;

export type RepeaterSub = {
  id: string;
  label: string;
  type: 'text' | 'number' | 'textarea' | 'date';
};

export type Field = {
  id: string;
  label: string;
  type: FieldType;
  hint?: string;
  required?: boolean;
  unit?: string;
  step?: number;
  options?: string[];
  showIf?: ShowIf;
  /** `'non-empty'` / `'any'` / specific value(s) that should surface a flag. */
  flagIf?: Trigger | 'non-empty' | 'any';
  criticalIf?: Trigger;
  /** Used by the auto-protocol decision tree; left as opaque metadata here. */
  protocolIf?: Record<string, string>;
  /** Repeater sub-fields. */
  sub?: RepeaterSub[];
};

export type Section = {
  id: string;
  nr: number;
  title: string;
  intro: string;
  /** Estimated minutes — used for the overview card. */
  minutes: number;
  /** Icon key used in the overview list. Matches CustomerIntake.jsx. */
  icon:
    | 'mail'
    | 'horse'
    | 'alert'
    | 'history'
    | 'heart'
    | 'leaf'
    | 'droplet'
    | 'home'
    | 'sparkles'
    | 'camera'
    | 'send';
  sub: string;
  fields: Field[];
};

export const INTAKE_SCHEMA: Section[] = [
  /* ============= 00 · CONTACT & OPENHEID ============= */
  {
    id: 'contact',
    nr: 0,
    title: 'Contactgegevens',
    intro: 'Eerst even hoe ik je kan bereiken, en of je openstaat voor veranderingen.',
    minutes: 2,
    icon: 'mail',
    sub: 'E-mail, telefoon, openheid voor aanpassingen',
    fields: [
      {
        id: 'email',
        label: 'E-mailadres',
        type: 'text',
        required: true,
        hint: 'Op dit adres stuur ik je protocol en kopie van je antwoorden.',
      },
      { id: 'naam-eigenaar', label: 'Jouw naam', type: 'text', required: true },
      {
        id: 'tel-eigenaar',
        label: 'Telefoonnummer',
        type: 'text',
        required: true,
        hint: 'Voor het geval ik je acuut wil bereiken, ik bel je niet zomaar.',
      },
      {
        id: 'hoe-gevonden',
        label: 'Hoe ben je bij De Paardentherapeut terecht gekomen?',
        type: 'textarea',
        required: true,
      },
      {
        id: 'aanpassingen-openheid',
        label: 'Sta je open voor aanpassingen in voer- en managementbeleid?',
        type: 'radio',
        required: true,
        options: ['Ja, helemaal', 'Ik twijfel nog, leg uit hieronder', 'Anders'],
        hint: 'Eerlijk zijn helpt mij realistisch te zijn.',
        flagIf: ['Ik twijfel nog, leg uit hieronder', 'Anders'],
      },
      {
        id: 'aanpassingen-toelichting',
        label: 'Toelichting',
        type: 'textarea',
        showIf: {
          'aanpassingen-openheid': ['Ik twijfel nog, leg uit hieronder', 'Anders'],
        },
      },
    ],
  },

  /* ============= 01 · OVER JE PAARD ============= */
  {
    id: 'paard',
    nr: 1,
    title: 'Over je paard',
    intro: 'De basics, zodat ik weet over wie we het hebben.',
    minutes: 4,
    icon: 'horse',
    sub: 'Ras, leeftijd, gewicht, conditie',
    fields: [
      { id: 'naam', label: 'Naam van je paard', type: 'text', required: true },
      {
        id: 'ras',
        label: 'Ras of kruising',
        type: 'text',
        required: true,
        hint: 'Niet zeker? "Onbekend" mag ook.',
      },
      {
        id: 'geboortedatum',
        label: 'Geboortedatum (zo precies mogelijk)',
        type: 'date',
        hint: 'Niet bekend? Vul leeftijd in jaren in onder.',
      },
      { id: 'leeftijd', label: 'Leeftijd', type: 'number', unit: 'jaar', required: true },
      {
        id: 'geslacht',
        label: 'Geslacht',
        type: 'radio',
        required: true,
        options: ['merrie', 'ruin', 'hengst'],
      },
      {
        id: 'gecastreerd',
        label: 'Op welke leeftijd gecastreerd?',
        type: 'text',
        showIf: { geslacht: 'ruin' },
      },
      {
        id: 'drachtig',
        label: 'Is ze drachtig of lacterend?',
        type: 'radio',
        options: ['nee', 'drachtig', 'lacterend'],
        showIf: { geslacht: 'merrie' },
        flagIf: ['drachtig', 'lacterend'],
        criticalIf: ['drachtig', 'lacterend'],
      },
      {
        id: 'gewicht',
        label: 'Geschat gewicht',
        type: 'number',
        unit: 'kg',
        required: true,
        hint: 'Belangrijk, alle doseringen in het protocol worden hierop berekend.',
      },
      {
        id: 'gewicht-methode',
        label: 'Hoe is dit gewicht bepaald?',
        type: 'radio',
        required: true,
        options: [
          'geschat op zicht',
          'gewogen op weegbrug',
          'gemeten met gewichts-lint',
          'rapport dierenarts',
        ],
      },
      {
        id: 'stokmaat',
        label: 'Stokmaat',
        type: 'number',
        unit: 'cm',
        step: 1,
        required: true,
      },
      {
        id: 'stokmaat-methode',
        label: 'Geschat of gemeten?',
        type: 'radio',
        options: ['geschat', 'gemeten'],
      },
      {
        id: 'adres-stal',
        label: 'Adres van de stal',
        type: 'textarea',
        required: true,
        hint: 'Straat + plaats, postcode helpt me bij regio-specifieke tips (bv. bodemtype).',
      },
      {
        id: 'sinds-bezit',
        label: 'Sinds wanneer is je paard bij jou?',
        type: 'text',
        required: true,
        hint: 'Maand + jaar is genoeg.',
      },
      {
        id: 'eerste-eigenaar',
        label: 'Ben je de eerste eigenaar?',
        type: 'radio',
        options: ['ja', 'nee', 'weet ik niet'],
      },
      {
        id: 'conditie',
        label: 'Hoe zou je de conditie classificeren?',
        type: 'radio',
        required: true,
        options: [
          'ondergewicht',
          'dun',
          'slank',
          'gespierd / sportief',
          'normaal',
          'iets te zwaar',
          'overgewicht',
          'obees',
        ],
        flagIf: ['ondergewicht', 'dun', 'iets te zwaar', 'overgewicht', 'obees'],
      },
      {
        id: 'conditie-veranderd',
        label: 'Is de conditie veranderd sinds je paard bij jou is?',
        type: 'textarea',
        required: true,
        hint: 'Zo ja, hoe? Aangekomen, afgevallen, omgekeerd?',
      },
      {
        id: 'gezondheid-eigen-woorden',
        label: 'Hoe omschrijf je de gezondheidsstatus op dit moment?',
        type: 'textarea',
        required: true,
        hint: 'In je eigen woorden, geen jargon nodig.',
      },
      {
        id: 'foto-paard',
        label: 'Foto van je paard (zijaanzicht)',
        type: 'photo',
        hint: 'Voor mijn dossier, zo weet ik gelijk met wie ik werk.',
      },
    ],
  },

  /* ============= 02 · KLACHT & HULPVRAAG ============= */
  {
    id: 'klacht',
    nr: 2,
    title: 'Klacht & hulpvraag',
    intro:
      'Wat speelt er nu? Wees zo open en gedetailleerd mogelijk, ik lees alles persoonlijk.',
    minutes: 6,
    icon: 'alert',
    sub: 'Wie is je paard, wat speelt er, wat wens je',
    fields: [
      {
        id: 'hulpvraag',
        label: 'Wat is je klacht of hulpvraag?',
        type: 'textarea',
        required: true,
      },
      {
        id: 'begonnen-wanneer',
        label: 'Wanneer is dit begonnen en onder welke omstandigheden?',
        type: 'textarea',
        required: true,
        hint: 'Was er een verhuizing, voerwissel, ziekte, seizoenswissel?',
      },
      {
        id: 'subklacht',
        label: 'Is er een subklacht / hulpvraag 2?',
        type: 'textarea',
        hint: 'Optioneel, als er meerdere dingen spelen.',
      },
      {
        id: 'wens',
        label: 'Wat is je wens van dit traject?',
        type: 'textarea',
        required: true,
      },
      {
        id: 'acuut',
        label: 'Heeft ze NU acute klachten?',
        type: 'multi',
        options: ['koorts', 'ernstige kreupelheid', 'koliek', 'wond / verwonding', 'geen'],
        hint: 'Bij acute klachten eerst dierenarts, het traject pauzeert dan.',
        criticalIf: ['koorts', 'ernstige kreupelheid', 'koliek'],
      },
      {
        id: 'thema',
        label: "Welke thema's spelen mee?",
        type: 'multi',
        options: [
          'Jeuk',
          'Darmen',
          'Staakgedrag',
          'Hoeven',
          'Voeding',
          'Spierspanning',
          'Ademhaling',
          'Houding & balans',
          'Gedrag',
          'Energie',
          'Luchtwegen',
          'Pees / gewricht',
        ],
      },
      {
        id: 'da-behandeling',
        label: 'Is het paard onder behandeling van een dierenarts?',
        type: 'radio',
        required: true,
        options: ['ja, met diagnose', 'ja, in onderzoek', 'nee'],
      },
      {
        id: 'da-diagnose',
        label: 'Welke diagnose is gesteld?',
        type: 'textarea',
        showIf: { 'da-behandeling': ['ja, met diagnose', 'ja, in onderzoek'] },
        flagIf: 'non-empty',
      },
      {
        id: 'eerder-behandeld',
        label: 'Al eerder behandeld door een therapeut voor déze klacht(en)?',
        type: 'radio',
        required: true,
        options: ['ja', 'nee'],
      },
      {
        id: 'eerder-wat',
        label: 'Zo ja, welk type therapeut en wanneer?',
        type: 'textarea',
        showIf: { 'eerder-behandeld': 'ja' },
      },
      {
        id: 'eerder-resultaat',
        label: 'Met welk resultaat?',
        type: 'textarea',
        showIf: { 'eerder-behandeld': 'ja' },
      },
      {
        id: 'huidige-aanpak',
        label: 'Wat doe je op dit moment aan de hulpvraag?',
        type: 'textarea',
        hint: 'Therapie, middelen, maatregelen, wat dan ook.',
      },
      {
        id: 'bloedonderzoek',
        label: 'Bloedonderzoek aanwezig?',
        type: 'file',
        hint: 'Upload PDF (max 5 bestanden, ieder max 10 MB).',
      },
      {
        id: 'foto-historie',
        label: "Foto's van de afgelopen 2–5 jaar",
        type: 'photo',
        hint: "Helpt mij om ontwikkeling te zien. Max 10 foto's.",
      },
      {
        id: 'gedragsveranderingen',
        label: 'Veranderingen in gedrag recent?',
        type: 'textarea',
        flagIf: 'non-empty',
      },
      { id: 'allergie', label: 'Bekende allergieën?', type: 'text' },
      {
        id: 'ervaring-holistisch',
        label: 'Ervaring met holistische therapieën?',
        type: 'textarea',
        hint: 'Osteopaat, kruidengeneeskunde, etc. Mag ook "geen".',
      },
      {
        id: 'stressfactoren',
        label: 'Stressfactoren of veranderingen recent',
        type: 'textarea',
        hint: 'Verhuizing, nieuwe stalgenoten, voerwissel, ander werk, …',
        flagIf: 'non-empty',
      },
      {
        id: 'leuk',
        label: 'Wat vindt je paard heel leuk?',
        type: 'textarea',
        hint: 'Helpt me beeld te krijgen van wat haar gelukkig maakt.',
      },
    ],
  },

  /* ============= 03 · GESCHIEDENIS ============= */
  {
    id: 'geschiedenis',
    nr: 3,
    title: 'Geschiedenis van je paard',
    intro: 'Hoe je paard groot is geworden, vaak verstopte oorzaken zitten hier.',
    minutes: 5,
    icon: 'history',
    sub: 'De eerste levensjaren, medisch verleden',
    fields: [
      {
        id: 'moeder-voer-huis',
        label: 'Hoe werd de moeder gevoerd en gehuisvest?',
        type: 'textarea',
        hint: 'Weet je niet? "Onbekend" mag.',
      },
      {
        id: 'eerste-maanden',
        label: 'Hoe heeft je paard de eerste maanden doorgebracht?',
        type: 'textarea',
        hint: 'Traditionele stalling, opfok, weiland, wildgebied, …',
      },
      {
        id: 'moeder-metabolisch',
        label: 'Had de moeder tekenen van metabolische problemen?',
        type: 'multi',
        options: [
          'mestwater',
          'diarree',
          'koliekgevoeligheid',
          'zomereczeem',
          'mok',
          'chronisch hoesten',
          'overgewicht',
          'EMS',
          'PPID',
          'hoefbevangenheid',
          'onbekend',
          'geen',
        ],
        flagIf: [
          'mestwater',
          'diarree',
          'koliekgevoeligheid',
          'zomereczeem',
          'mok',
          'chronisch hoesten',
          'overgewicht',
          'EMS',
          'PPID',
          'hoefbevangenheid',
        ],
      },
      {
        id: 'spenen-inrijden-huis',
        label: 'Hoe woonde je paard tussen spenen en inrijden?',
        type: 'textarea',
        hint: 'Kuddegenoten, alleen, op stal, op het land, …',
      },
      {
        id: 'spenen-inrijden-voer',
        label: 'Hoe werd ze gevoerd in die periode?',
        type: 'textarea',
        hint: 'Ruwvoer verpakt/onverpakt, bijvoeding, …',
      },
      {
        id: 'spenen-inrijden-symptomen',
        label: 'Heeft ze in die periode symptomen / ziektes ontwikkeld?',
        type: 'textarea',
        hint: 'Welke, wanneer, hoe behandeld?',
        flagIf: 'non-empty',
      },
      {
        id: 'medische-geschiedenis-volledig',
        label: 'Volledige medische geschiedenis, chronologisch',
        type: 'textarea',
        required: true,
        hint:
          'Ziekte, symptomen, bijzonderheden waarvoor de DA kwam, in chronologische volgorde met (geschatte) data + welke behandelmethodes + wat wel/niet aansloeg.',
        flagIf: 'non-empty',
      },
    ],
  },

  /* ============= 04 · MEDISCH & SPECIALISTEN ============= */
  {
    id: 'medisch',
    nr: 4,
    title: 'Medisch & specialisten',
    intro: 'Vaccinaties, ontworming, tandarts, hoefsmid, zadelmaker.',
    minutes: 6,
    icon: 'heart',
    sub: 'Vaccinaties, gebit, hoeven enz.',
    fields: [
      { id: 'sec-vacc', label: 'Vaccinatie en ontworming', type: 'sectionhead' },
      {
        id: 'vaccinaties',
        label: 'Welke vaccinaties krijgt het paard?',
        type: 'multi',
        options: ['Tetanus', 'Influenza', 'Rhinopneumonie', 'West Nijl', 'Geen', 'Anders'],
      },
      {
        id: 'vacc-laatst',
        label: 'Wanneer voor het laatst gevaccineerd?',
        type: 'date',
        required: true,
      },
      {
        id: 'vacc-volgende',
        label: 'Volgende vaccinatie staat gepland op',
        type: 'text',
        hint: 'Datum of seizoen.',
      },
      {
        id: 'ontworming-laatst',
        label: 'Wanneer voor het laatst ontwormd?',
        type: 'date',
        required: true,
      },
      {
        id: 'ontworming-middel',
        label: 'Met welk wormmiddel?',
        type: 'text',
        required: true,
      },
      {
        id: 'ontworming-volgende',
        label: 'Wanneer volgende ontworming?',
        type: 'text',
        required: true,
      },
      {
        id: 'ontworming-strategie',
        label: 'Op basis van mestonderzoek of vast schema?',
        type: 'radio',
        options: ['vast schema', 'mestonderzoek', 'mix'],
      },

      { id: 'sec-tand', label: 'Gebit en tandarts', type: 'sectionhead' },
      {
        id: 'tandarts-freq',
        label: 'Hoe vaak wordt het gebit gecontroleerd?',
        type: 'radio',
        required: true,
        options: ['jaarlijks', 'om de 6 maanden', 'op afroep', 'nog nooit'],
        flagIf: ['nog nooit'],
      },
      {
        id: 'tandarts-laatst',
        label: 'Wanneer is de tandarts voor het laatst geweest?',
        type: 'date',
        required: true,
      },
      {
        id: 'tandarts-volgende',
        label: 'Wanneer komt de tandarts weer?',
        type: 'text',
        required: true,
      },
      {
        id: 'tandarts-methode',
        label: 'Hoe wordt het gebit behandeld?',
        type: 'radio',
        required: true,
        options: [
          'handmatig zonder verdoving',
          'handmatig met verdoving',
          'elektrisch zonder verdoving',
          'elektrisch met verdoving',
          'mix / weet ik niet',
        ],
      },
      {
        id: 'tandarts-bijz',
        label: 'Bijzonderheden bij laatste tandartsbezoek?',
        type: 'textarea',
        required: true,
        flagIf: 'non-empty',
      },

      { id: 'sec-hoef', label: 'Hoeven en beslag', type: 'sectionhead' },
      {
        id: 'hoefsmid-freq',
        label: 'Hoe vaak komt de bekapper / hoefsmid?',
        type: 'radio',
        required: true,
        options: ['elke 4 weken', 'elke 5 weken', 'elke 6 weken', 'elke 7–8 weken', 'op afroep'],
      },
      {
        id: 'hoeven-bijz',
        label: 'Bijzonderheden mbt de voeten?',
        type: 'multi',
        required: true,
        options: [
          'afwijkende hoefstand',
          'white line disease',
          'rotstraal',
          'hoefkanker',
          'regelmatig hoefzweren',
          'geen',
          'anders',
        ],
        flagIf: [
          'afwijkende hoefstand',
          'white line disease',
          'rotstraal',
          'hoefkanker',
          'regelmatig hoefzweren',
        ],
      },
      {
        id: 'hoeven-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'hoeven-bijz': 'anders' },
      },
      {
        id: 'ijzers',
        label: 'Staat het paard op ijzers?',
        type: 'radio',
        required: true,
        options: ['ja, rondom', 'ja, voor', 'ja, achter', 'nee', 'anders'],
      },

      { id: 'sec-zadel', label: 'Zadel', type: 'sectionhead' },
      {
        id: 'zadelmaker-freq',
        label: 'Hoe vaak komt de zadelmaker?',
        type: 'text',
        required: true,
      },
      {
        id: 'zadelmaker-laatst',
        label: 'Laatste bezoek zadelmaker',
        type: 'date',
      },
      {
        id: 'zadel-bijz',
        label: 'In het verleden bijzonderheden mbt het zadel?',
        type: 'textarea',
        flagIf: 'non-empty',
      },

      {
        id: 'sec-aandoening',
        label: 'Aangetoonde of vermoedelijke aandoeningen',
        type: 'sectionhead',
      },
      {
        id: 'ir-status',
        label: 'Insulineresistentie (IR)',
        type: 'radio',
        required: true,
        options: ['aangetoond door dierenarts', 'vermoeden van mij', 'nee'],
        flagIf: ['aangetoond door dierenarts', 'vermoeden van mij'],
      },
      {
        id: 'ems-status',
        label: 'EMS (Equine Metabolisch Syndroom)',
        type: 'radio',
        required: true,
        options: ['aangetoond door dierenarts', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond door dierenarts'],
      },
      {
        id: 'kpu-status',
        label: 'KPU (Kryptopyrrolurie)',
        type: 'radio',
        required: true,
        options: ['aangetoond door dierenarts', 'vermoeden van mij', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond door dierenarts', 'vermoeden van mij'],
      },
      {
        id: 'kpu-attest',
        label: 'KPU-rapport beschikbaar?',
        type: 'file',
        showIf: { 'kpu-status': 'aangetoond door dierenarts' },
      },
      {
        id: 'hoefbevangenheid',
        label: 'Hoefbevangenheid (geweest of risico)',
        type: 'radio',
        required: true,
        options: ['nu acuut', 'in verleden gehad', 'risico / vermoeden', 'nooit'],
        flagIf: ['nu acuut', 'in verleden gehad', 'risico / vermoeden'],
        criticalIf: ['nu acuut'],
      },

      { id: 'sec-maag', label: 'Maag', type: 'sectionhead' },
      {
        id: 'maag-ondersteuning',
        label: 'Heeft de maag ondersteuning nodig?',
        type: 'radio',
        required: true,
        options: ['ja, ernstig / langdurig', 'ja, mild', 'nee'],
        flagIf: ['ja, ernstig / langdurig', 'ja, mild'],
      },
      {
        id: 'maag-symptomen',
        label: 'Tekenen van maagproblemen',
        type: 'multi',
        options: [
          'gevoelige buikriem',
          'oren plat bij voeren',
          'maagzweer aangetoond',
          'soppen van hooi in water',
          'gespannen bij eten',
          'tandenknarsen',
          'geen',
        ],
        flagIf: 'any',
      },
      {
        id: 'maag-medicatie',
        label: 'Eerder gastro-medicatie gehad?',
        type: 'radio',
        options: ['ja, herhaaldelijk', 'ja, één kuur', 'nee'],
        flagIf: ['ja, herhaaldelijk', 'ja, één kuur'],
      },

      { id: 'sec-darm', label: 'Darmen', type: 'sectionhead' },
      {
        id: 'gasserig',
        label: 'Is je paard gasserig / opgeblazen?',
        type: 'radio',
        required: true,
        options: ['ja, regelmatig', 'soms', 'nee'],
        flagIf: ['ja, regelmatig', 'soms'],
      },
      {
        id: 'probiotica-geschiedenis',
        label: 'Jarenlang probiotica / gist / yeast gehad?',
        type: 'radio',
        required: true,
        options: ['ja, >2 jaar', 'ja, <2 jaar', 'nee', 'weet ik niet'],
        flagIf: ['ja, >2 jaar', 'ja, <2 jaar'],
      },
      {
        id: 'medicatie-nu',
        label: 'Medicatie nu?',
        type: 'textarea',
        hint: 'Type, dosering, vanaf wanneer.',
        flagIf: 'non-empty',
      },
      {
        id: 'medicatie-recent',
        label: 'Medicatie laatste jaar?',
        type: 'textarea',
        flagIf: 'non-empty',
      },
    ],
  },

  /* ============= 05 · VOER & RUWVOER ============= */
  {
    id: 'voer',
    nr: 5,
    title: 'Voer & ruwvoer',
    intro: 'Dit is vaak de kern. Wees specifiek, merknamen, hoeveelheden, exacte tijden.',
    minutes: 12,
    icon: 'leaf',
    sub: 'Hooi, krachtvoer, mineralen, supplementen',
    fields: [
      { id: 'sec-ruw', label: 'Ruwvoer', type: 'sectionhead' },
      {
        id: 'hooi-verpakking',
        label: 'Eet je paard hooi uit plastic of uit touwtjes?',
        type: 'radio',
        required: true,
        options: ['onverpakt (touwtjes)', 'verpakt (plastic / baal)', 'mix'],
        flagIf: ['verpakt (plastic / baal)'],
      },
      {
        id: 'hooi-omschrijving',
        label: 'Hoe omschrijf je het ruwvoer?',
        type: 'multi',
        required: true,
        options: [
          'zacht',
          'grof',
          'veel blad',
          'veel stengels',
          'groenig',
          'gelig',
          'veel verschillende planten',
          'veel verschillende grassen',
          '1 of 2 soorten grassen',
          'kruidig',
          'eerste snede',
          'tweede snede',
          'rijk hooi',
          'arm hooi',
        ],
        flagIf: ['rijk hooi', 'gelig', '1 of 2 soorten grassen'],
      },
      {
        id: 'hooi-kwaliteit-geur',
        label: 'Kwaliteit & geur van het hooi',
        type: 'multi',
        required: true,
        options: ['ruikt lekker', 'stoffig', 'neutraal', 'schimmelig', 'zuur'],
        flagIf: ['stoffig', 'schimmelig', 'zuur'],
      },
      {
        id: 'hooi-analyse',
        label: 'Hooi-analyse aanwezig?',
        type: 'file',
        hint: 'Eén PDF, max 10 MB.',
      },
      {
        id: 'hooi-constant',
        label: 'Is de kwaliteit constant of wisselend?',
        type: 'radio',
        required: true,
        options: ['constant', 'wisselt per baal', 'wisselt per levering', 'wisselt per leverancier'],
        flagIf: ['wisselt per baal', 'wisselt per levering', 'wisselt per leverancier'],
      },
      {
        id: 'hooi-herkomst',
        label: 'Eigen land of ingekocht?',
        type: 'radio',
        required: true,
        options: ['eigen land', 'ingekocht', 'mix', 'weet ik niet'],
      },
      {
        id: 'hooi-aanbod',
        label: 'Hoe wordt het hooi gevoerd?',
        type: 'textarea',
        required: true,
        hint:
          'Los of in slowfeeders? Maasbreedte? Aantal voerplekken? Automatisch systeem? Continu of in porties?',
      },
      {
        id: 'hooi-pauze-incl-nacht',
        label: 'Bij porties: hoe lang zonder ruwvoer, INCL. nachten?',
        type: 'radio',
        options: ['<2 u', '2–4 u', '4–6 u', '6–8 u', '>8 u'],
        flagIf: ['4–6 u', '6–8 u', '>8 u'],
      },
      {
        id: 'hooi-voerbeurten',
        label: 'In hoeveel voerbeurten?',
        type: 'radio',
        options: ['1', '2', '3', '4', '>4', 'onbeperkt / continu'],
      },
      {
        id: 'hooi-eerst-ruwvoer',
        label: "Krijgt het paard 's ochtends eerst ruwvoer of krachtvoer?",
        type: 'radio',
        options: ['eerst ruwvoer', 'eerst krachtvoer', 'tegelijk'],
        flagIf: ['eerst krachtvoer'],
      },
      {
        id: 'hooi-kg-per-dag',
        label: 'Hoeveel kg ruwvoer krijgt je paard per dag?',
        type: 'number',
        unit: 'kg',
        required: true,
        hint:
          'Heel veel mensen weten dit niet en gokken er flink naast. Weeg het een keer (bijv. met een weegschaal of bagageweger aan het hooinet) voordat je invult.',
      },
      {
        id: 'hooi-kg-methode',
        label: 'Is deze hoeveelheid geschat of gewogen?',
        type: 'radio',
        required: true,
        options: ['gewogen', 'geschat'],
        hint: 'Eerlijk zijn helpt mij. Bij geschat reken ik met een ruimere marge.',
        flagIf: ['geschat'],
      },
      {
        id: 'foto-hooi',
        label: 'Foto van het hooi',
        type: 'photo',
        hint: 'Eén hapje uit een baal, op een neutrale ondergrond.',
      },

      { id: 'sec-voordroog', label: 'Voordroog en kuil', type: 'sectionhead' },
      {
        id: 'voordroog-verleden',
        label: 'Ooit voordroog of kuil gegeten?',
        type: 'radio',
        required: true,
        options: ['ja, nu nog', 'ja, vroeger', 'nee'],
        flagIf: ['ja, nu nog'],
      },
      {
        id: 'voordroog-duur-vroeger',
        label: 'Hoe lang en hoeveel, in het verleden?',
        type: 'textarea',
        showIf: { 'voordroog-verleden': ['ja, vroeger'] },
      },
      {
        id: 'voordroog-nu-mix',
        label: 'Volledig voordroog/kuil of gemixt met hooi?',
        type: 'radio',
        options: ['volledig', 'gemixt met onverpakt hooi'],
        showIf: { 'voordroog-verleden': 'ja, nu nog' },
      },
      {
        id: 'voordroog-kwaliteit',
        label: 'Kwaliteit voordroog/kuil',
        type: 'multi',
        options: ['droog', 'nat', 'zuur', 'neutrale geur', 'anders'],
        showIf: { 'voordroog-verleden': 'ja, nu nog' },
        flagIf: ['nat', 'zuur'],
      },
      {
        id: 'voordroog-tempo',
        label: 'Hoe lang duurt 1 baal voordat een nieuwe wordt opengemaakt?',
        type: 'text',
        showIf: { 'voordroog-verleden': 'ja, nu nog' },
      },

      { id: 'sec-stro', label: 'Stro', type: 'sectionhead' },
      {
        id: 'stro-aanwezig',
        label: 'Geef je wel eens stro?',
        type: 'radio',
        required: true,
        options: ['ja', 'nee'],
      },
      {
        id: 'stro-manier',
        label: 'Op wat voor manier?',
        type: 'multi',
        options: ['als bodembedekking', 'in slowfeeders', 'gemixt met hooi', 'anders'],
        showIf: { 'stro-aanwezig': 'ja' },
      },
      {
        id: 'stro-type',
        label: 'Wat voor stro?',
        type: 'text',
        showIf: { 'stro-aanwezig': 'ja' },
      },
      {
        id: 'stro-hoeveelheid',
        label: 'Hoeveel stro eet je paard per dag?',
        type: 'text',
        hint: 'Zou je hem/haar een "stro-eter" noemen?',
        showIf: { 'stro-aanwezig': 'ja' },
      },
      {
        id: 'stro-kwaliteit',
        label: 'Kwaliteit stro',
        type: 'multi',
        options: ['gelig', 'grijzig', 'ruikt fris', 'ruikt schimmelig', 'ruikt chemisch', 'anders'],
        showIf: { 'stro-aanwezig': 'ja' },
        flagIf: ['grijzig', 'ruikt schimmelig', 'ruikt chemisch'],
      },

      { id: 'sec-kracht', label: 'Krachtvoer en bijvoer', type: 'sectionhead' },
      {
        id: 'voer-gewisseld',
        label: 'Recent gewisseld van (ruw)voer?',
        type: 'radio',
        required: true,
        options: ['ja, <3 maanden', 'ja, <6 maanden', 'nee'],
        flagIf: ['ja, <3 maanden'],
      },
      {
        id: 'voer-gewisseld-toelichting',
        label: 'Toelichting voerwissel',
        type: 'textarea',
        hint: 'Wat kreeg ze hiervoor en wanneer overgegaan?',
        showIf: { 'voer-gewisseld': ['ja, <3 maanden', 'ja, <6 maanden'] },
      },
      {
        id: 'krachtvoer-detail',
        label: 'Welk krachtvoer en in welke hoeveelheid?',
        type: 'textarea',
        required: true,
        hint: 'Merknamen + type EXACT benoemen. Bv. "Pavo Slobber, 1 schep per ochtend".',
      },
      {
        id: 'bijvoer-5jaar',
        label: 'Bijvoer afgelopen 5 jaar',
        type: 'textarea',
        required: true,
        hint: "Muesli's, granen, bietenpulp, gehakseld ruwvoer, enz.",
      },
      {
        id: 'balancer',
        label: 'Balancer / mineralenvoeding?',
        type: 'textarea',
        required: true,
        hint: 'Merk, type, hoeveelheid.',
      },
      {
        id: 'mineralen-toegang',
        label: 'Toegang tot welke mineralen?',
        type: 'multi',
        required: true,
        options: [
          'gewone liksteen',
          'Himalaya zoutsteen',
          'Veendrenkstof',
          'IJslands zeewier',
          'Keltisch zeezout',
          'PN natuursteen',
          'MSM (zwavel)',
          'geen',
          'anders',
        ],
      },

      { id: 'sec-supp', label: 'Snacks en supplementen', type: 'sectionhead' },
      {
        id: 'snacks-aanwezig',
        label: 'Krijgt je paard snacks?',
        type: 'radio',
        required: true,
        options: ['ja', 'nee'],
      },
      {
        id: 'snacks-detail',
        label: 'Wat geef je precies en hoeveel?',
        type: 'textarea',
        hint: 'Wortel, appel, fruit, brood, paardensnoepjes (met merk/type), …',
        showIf: { 'snacks-aanwezig': 'ja' },
      },
      {
        id: 'huidig-extra',
        label: 'Alle huidige medicijnen, supplementen, bijvoeding',
        type: 'repeater',
        required: true,
        hint: 'Hoeveelheid, merk, sinds wanneer.',
        sub: [
          { id: 'naam', label: 'Naam', type: 'text' },
          { id: 'merk', label: 'Merk / type', type: 'text' },
          { id: 'dosering', label: 'Dosering', type: 'text' },
          { id: 'sinds', label: 'Sinds wanneer', type: 'text' },
          { id: 'reden', label: 'Waarom', type: 'text' },
        ],
        flagIf: 'any',
      },
      {
        id: 'historie-extra',
        label: 'Alle medicijnen / supplementen / bijvoeding LAATSTE 5 JAAR',
        type: 'repeater',
        required: true,
        hint: 'Hoeveelheid, merk, periode (van–tot).',
        sub: [
          { id: 'naam', label: 'Naam', type: 'text' },
          { id: 'merk', label: 'Merk / type', type: 'text' },
          { id: 'dosering', label: 'Dosering', type: 'text' },
          { id: 'periode', label: 'Periode (van – tot)', type: 'text' },
        ],
      },
    ],
  },

  /* ============= 06 · WATER & UITSCHEIDING ============= */
  {
    id: 'water',
    nr: 6,
    title: 'Water & uitscheiding',
    intro: 'Hoe drinkt ze, en wat komt eruit.',
    minutes: 5,
    icon: 'droplet',
    sub: 'Vochtinname, urine, mest',
    fields: [
      {
        id: 'water-type',
        label: 'Wat voor water drinkt het paard?',
        type: 'radio',
        required: true,
        options: [
          'leidingwater',
          'regenwater',
          'grondwater',
          'slootwater / oppervlaktewater',
          'weet ik niet',
          'anders',
        ],
        flagIf: ['slootwater / oppervlaktewater', 'weet ik niet'],
      },
      {
        id: 'water-analyse',
        label: 'Water-analyse aanwezig?',
        type: 'file',
        hint: 'Vooral relevant als geen leidingwater, upload PDF.',
        showIf: {
          'water-type': ['regenwater', 'grondwater', 'slootwater / oppervlaktewater', 'anders'],
        },
      },
      {
        id: 'water-aanbod',
        label: 'Hoe wordt het water aangeboden?',
        type: 'radio',
        required: true,
        options: [
          'speciekuipen / plastic emmers / troggen',
          'automatische drinkbakken',
          'anders',
        ],
      },
      {
        id: 'water-toegang',
        label: 'Hele dag toegang tot drinkwater?',
        type: 'radio',
        required: true,
        options: ['ja', 'nee'],
        flagIf: ['nee'],
      },
      {
        id: 'water-kwaliteit',
        label: 'Hoe omschrijf je de kwaliteit van het water?',
        type: 'multi',
        required: true,
        options: [
          'neutraal / schoon',
          'zwavelachtig',
          'algen aanwezig',
          'aanslag',
          'modderig',
          'ijzer / zware metalen',
          'gelig',
          'bruinig',
          'groenig',
          'koud',
          'lauw',
        ],
        flagIf: [
          'zwavelachtig',
          'algen aanwezig',
          'aanslag',
          'modderig',
          'ijzer / zware metalen',
          'gelig',
          'bruinig',
          'groenig',
        ],
      },
      {
        id: 'vochtinname',
        label: 'Hoe is de vochtinname?',
        type: 'radio',
        required: true,
        options: ['lijkt normaal', 'lijkt minder', 'lijkt meer', 'weet ik niet'],
        flagIf: ['lijkt minder', 'lijkt meer'],
      },

      { id: 'sec-uit', label: 'Urine en mest', type: 'sectionhead' },
      {
        id: 'urine',
        label: 'Urine',
        type: 'multi',
        options: ['helder', 'gelig', 'oranje', 'troebel', 'normaal volume', 'minder', 'meer'],
        flagIf: ['oranje', 'troebel', 'minder', 'meer'],
      },
      {
        id: 'mest-freq',
        label: 'Mest-frequentie per dag',
        type: 'radio',
        options: ['<6×', '6–10×', '10–14×', '>14×'],
      },
      {
        id: 'mest-geur',
        label: 'Geur van mest',
        type: 'radio',
        options: ['normaal', 'iets penetrant', 'sterk / rottend'],
        flagIf: ['iets penetrant', 'sterk / rottend'],
      },
      {
        id: 'mest-kleur',
        label: 'Kleur van mest',
        type: 'radio',
        options: ['groen', 'bruingroen', 'bruin', 'donkerbruin', 'zwart'],
        flagIf: ['zwart'],
      },
      {
        id: 'mest-vorm',
        label: 'Hoe ziet de vorm en vastheid van de mest eruit?',
        type: 'radio',
        required: true,
        options: [
          'waterig / diarree',
          'zacht of smeuïg (geen vaste appels)',
          'normale, vaste appels',
          'droog en hard',
          'wisselt per dag',
        ],
        flagIf: [
          'waterig / diarree',
          'zacht of smeuïg (geen vaste appels)',
          'droog en hard',
          'wisselt per dag',
        ],
      },
      {
        id: 'foto-mest',
        label: 'Foto van mest (laatste 24u)',
        type: 'photo',
        required: true,
        hint: 'Eén hoopje, op een schone ondergrond. Cruciale info voor mij.',
      },
    ],
  },

  /* ============= 07 · HUISVESTING & WEIDEGANG ============= */
  {
    id: 'huisvesting',
    nr: 7,
    title: 'Huisvesting & weide',
    intro: 'Waar woont ze, zomer én winter, en met wie.',
    minutes: 8,
    icon: 'home',
    sub: 'Zomer + winter, sociale groep, grasland',
    fields: [
      { id: 'sec-huis', label: 'Huisvesting (zomer en winter)', type: 'sectionhead' },
      {
        id: 'huisvesting-zomer',
        label: 'Hoe wordt je paard in de zomer gehuisvest?',
        type: 'textarea',
        required: true,
        hint: 'Individuele box, box met uitloop, paddock, 24/7 weidegang, …',
      },
      {
        id: 'huisvesting-winter',
        label: 'Hoe in de winter?',
        type: 'textarea',
        required: true,
      },
      {
        id: 'stal-uren',
        label: 'Hoeveel uur staat hij/zij per dag op stal?',
        type: 'radio',
        required: true,
        options: ['0 u', '1–4 u', '4–8 u', '8–12 u', '12–18 u', '>18 u', '24 u'],
        flagIf: ['12–18 u', '>18 u', '24 u'],
      },
      {
        id: 'buiten-niet-stal',
        label: 'Als niet op stal, hoe wordt het paard dan gehuisvest?',
        type: 'multi',
        required: true,
        options: ['zandpaddock', 'weiland', 'verharding', 'kunststofvloer', 'anders'],
      },
      {
        id: 'bodembedekking',
        label: 'Bodembedekking in de stal',
        type: 'multi',
        required: true,
        options: ['stro', 'houtkrullen', 'vlas', 'zaagsel', 'rubberen matten', 'zand', 'geen', 'anders'],
        flagIf: ['zaagsel'],
      },
      { id: 'foto-stal', label: 'Foto van haar stal of paddock', type: 'photo' },

      { id: 'sec-weide', label: 'Weidegang', type: 'sectionhead' },
      {
        id: 'gras-maanden',
        label: 'Hoeveel maanden per jaar op gras?',
        type: 'radio',
        required: true,
        options: ['0', '1–2', '3–4', '5–6', '7–8', '>8', 'jaarrond'],
      },
      {
        id: 'gras-uren',
        label: 'In die maanden, hoeveel uur per dag op gras?',
        type: 'radio',
        required: true,
        options: ['0', '1–2 u', '3–4 u', '5–8 u', '9–12 u', '>12 u', '24 u'],
      },
      {
        id: 'nooit-gras',
        label: 'Komt ze ÓÓIT op echt gras (ook niet 2u/dag in zomer)?',
        type: 'radio',
        required: true,
        options: ['ja, regelmatig', 'ja, sporadisch', 'nee, nooit'],
        hint: 'Cruciaal voor de vitamine E vraag, alleen "nee" = nooit een seconde.',
        flagIf: ['nee, nooit'],
      },
      {
        id: 'mestwater-overgang',
        label: 'Mestwater bij transitie hooi ↔ gras?',
        type: 'multi',
        required: true,
        options: ['ja, begin', 'ja, einde', 'nee'],
        flagIf: ['ja, begin', 'ja, einde'],
      },
      {
        id: 'gras-type',
        label: 'Type grasland',
        type: 'multi',
        required: true,
        options: ['paardengras', 'koeiengras', 'kruidenrijk', 'weet ik niet', 'anders'],
        flagIf: ['koeiengras'],
      },
      {
        id: 'gras-bemest',
        label: 'Bemest of niet bemest?',
        type: 'radio',
        options: ['niet bemest', 'wel bemest', 'weet ik niet'],
        flagIf: ['wel bemest'],
      },
      {
        id: 'bomen-struiken',
        label: 'Bomen of struiken aan / in de weide',
        type: 'textarea',
        hint: 'Welke soorten? Bereikbaar?',
        flagIf: 'non-empty',
      },

      { id: 'sec-sociaal', label: 'Sociale huisvesting', type: 'sectionhead' },
      {
        id: 'groep-grootte',
        label: 'Hoeveel paarden in de groep?',
        type: 'number',
        unit: 'paarden',
      },
      {
        id: 'groep-mix',
        label: 'Ruinen en merries apart of samen?',
        type: 'radio',
        options: ['samen', 'apart', 'n.v.t.'],
      },
      {
        id: 'groep-leeftijden',
        label: 'Min – max leeftijd in de groep',
        type: 'text',
        hint: 'Bv. "4 t/m 18 jaar".',
      },
      {
        id: 'groep-interactie',
        label: 'Hoe is de sociale interactie?',
        type: 'textarea',
        flagIf: 'non-empty',
      },
      {
        id: 'groep-maatjes',
        label: 'Heeft jouw paard specifieke maatjes?',
        type: 'textarea',
        hint: 'Wat voor paard(en)?',
      },
      {
        id: 'groep-hierarchie',
        label: 'Waar staat jouw paard in de hiërarchie?',
        type: 'radio',
        options: ['bovenin', 'midden', 'onderaan', 'wisselend', 'weet ik niet'],
      },
      {
        id: 'groep-ruimte',
        label: 'Genoeg ruimte om zich af te zonderen?',
        type: 'radio',
        options: ['ja', 'matig', 'nee'],
        flagIf: ['matig', 'nee'],
      },
      {
        id: 'groep-pesten',
        label: 'Pestgedrag, stress of depressie zichtbaar?',
        type: 'textarea',
        flagIf: 'non-empty',
      },
      {
        id: 'andere-dieren',
        label: 'Andere diersoorten in de buurt',
        type: 'multi',
        options: ['schapen', 'koeien', 'geiten', 'kippen', 'honden', 'katten', 'wild'],
      },
    ],
  },

  /* ============= 08 · GEDRAG, TRAINING & KARAKTER ============= */
  {
    id: 'gedrag',
    nr: 8,
    title: 'Gedrag & training',
    intro: 'Wie is ze? Hoe gaat ze met de wereld om, en hoe wordt ze ingezet?',
    minutes: 6,
    icon: 'sparkles',
    sub: 'Discipline, stalondeugden, karakter',
    fields: [
      { id: 'sec-bew', label: 'Beweging en training', type: 'sectionhead' },
      {
        id: 'beweging-arbeid',
        label: 'Hoeveel dagelijkse beweging mbt arbeid?',
        type: 'text',
        required: true,
        hint: 'Aantal uren per dag, en wat voor soort werk.',
      },
      { id: 'discipline', label: 'Welke discipline?', type: 'text', required: true },
      {
        id: 'training-freq',
        label: 'Hoe vaak per week getraind?',
        type: 'radio',
        required: true,
        options: ['0×', '1–2×', '3–4×', '5–7×'],
      },
      {
        id: 'training-intensiteit',
        label: 'Gemiddelde intensiteit',
        type: 'radio',
        required: true,
        options: ['licht', 'matig', 'zwaar', 'wisselend'],
      },
      {
        id: 'training-knelpunten',
        label: 'Specifieke dingen tijdens training waar je tegenaan loopt?',
        type: 'textarea',
        required: true,
        flagIf: 'non-empty',
      },
      {
        id: 'conditie-eigen',
        label: 'Wat vind je van haar conditie?',
        type: 'textarea',
        required: true,
      },

      { id: 'sec-stal', label: 'Stalondeugden en opvallend gedrag', type: 'sectionhead' },
      {
        id: 'stress-symptomen',
        label: 'Stress-symptomen?',
        type: 'multi',
        required: true,
        options: ['weven', 'boxlopen', 'kribbenbijten', 'luchtzuigen', 'flemen', 'geen'],
        flagIf: ['weven', 'boxlopen', 'kribbenbijten', 'luchtzuigen', 'flemen'],
      },
      {
        id: 'headshaking',
        label: 'Headshaking?',
        type: 'radio',
        required: true,
        options: ['ja, regelmatig', 'soms', 'nee'],
        flagIf: ['ja, regelmatig', 'soms'],
      },
      {
        id: 'agressie',
        label: 'Overmatig agressief of beschermend?',
        type: 'radio',
        required: true,
        options: ['ja', 'nee'],
        flagIf: ['ja'],
      },
      {
        id: 'agressie-detail',
        label: 'Beschrijving',
        type: 'textarea',
        showIf: { agressie: 'ja' },
      },
      {
        id: 'typisch-gedrag',
        label: 'Typisch / apart / onverklaarbaar gedrag?',
        type: 'multi',
        required: true,
        options: [
          'soppen van hooi',
          'gretigheid naar specifiek voer',
          'kieskeurig met voer',
          'gevoelige buik',
          'boos bij aansingelen',
          'oren plat bij verzorging',
          'gevoelig op rug',
          'kan niet stilstaan',
          'geen',
        ],
        flagIf: 'any',
      },
      {
        id: 'typisch-gedrag-detail',
        label: 'Beschrijving',
        type: 'textarea',
        showIf: { 'typisch-gedrag': 'any-checked' },
      },
      {
        id: 'soortgenoten',
        label: 'Omgang met soortgenoten',
        type: 'multi',
        options: ['vriendelijk', 'dominant', 'onderworpen', 'gespannen', 'speels', 'afstandelijk'],
      },
      {
        id: 'stress-triggers',
        label: 'Waar krijgt ze stress van?',
        type: 'textarea',
      },
      {
        id: 'karakter',
        label: 'Karakter, in eigen woorden, beknopt',
        type: 'textarea',
        required: true,
        hint: 'Wat maakt haar uniek.',
      },
    ],
  },

  /* ============= 09 · FYSIEK & FOTO'S ============= */
  {
    id: 'fysiek',
    nr: 9,
    title: "Fysiek & foto's",
    intro: "Een korte check + foto's. Geen perfecte fotograaf nodig, daglicht is genoeg.",
    minutes: 10,
    icon: 'camera',
    sub: 'Huid, vacht, hoeven, ademhaling',
    fields: [
      {
        id: 'huid',
        label: 'Hoe ziet de huid eruit?',
        type: 'multi',
        required: true,
        options: [
          'droog',
          'vet',
          'rood',
          'schilfers',
          'kale plekken',
          'wratten',
          'mok / krukken',
          'normaal',
        ],
        flagIf: ['rood', 'schilfers', 'kale plekken', 'mok / krukken'],
      },
      {
        id: 'haar',
        label: 'Vacht / haar',
        type: 'multi',
        required: true,
        options: [
          'glanzend',
          'dof',
          'kruinen',
          'verkleurd',
          'wisselt sterk met seizoen',
          'kaal in plekken',
        ],
        flagIf: ['dof', 'kruinen', 'verkleurd', 'kaal in plekken'],
      },
      {
        id: 'hoeven-kwaliteit',
        label: 'Hoeven, visueel',
        type: 'multi',
        options: [
          'sterk',
          'brokkelig',
          'gelijke afslijting',
          'ongelijke afslijting',
          'kloven',
          'ringen',
          'lange teen',
        ],
        flagIf: ['brokkelig', 'ongelijke afslijting', 'kloven', 'ringen', 'lange teen'],
      },
      {
        id: 'slijmvliezen',
        label: 'Slijmvliezen (tandvlees, oogwit)',
        type: 'multi',
        options: ['roze', 'bleek', 'donkerrood', 'gelig', 'plakkerig', 'normaal vochtig'],
        flagIf: ['bleek', 'donkerrood', 'gelig', 'plakkerig'],
      },
      {
        id: 'ademhaling',
        label: 'Ademhaling in rust',
        type: 'radio',
        required: true,
        options: ['rustig', 'iets snel', 'hijgend', 'piepend', 'hoest'],
        flagIf: ['iets snel', 'hijgend', 'piepend', 'hoest'],
      },
      {
        id: 'bouw',
        label: 'Bouw',
        type: 'multi',
        options: ['rank', 'zwaar', 'kort', 'lang', 'gespierd', 'slank'],
      },
      {
        id: 'bespiering',
        label: 'Bespiering links–rechts gelijk?',
        type: 'radio',
        options: [
          'ja, gelijk',
          'links meer',
          'rechts meer',
          'voor meer dan achter',
          'achter meer dan voor',
        ],
        flagIf: ['links meer', 'rechts meer', 'voor meer dan achter', 'achter meer dan voor'],
      },
      {
        id: 'fysiek-vrij',
        label: 'Iets fysieks dat je opvalt en niet hierboven past?',
        type: 'textarea',
      },

      {
        id: 'foto-zijaanzicht',
        label: 'Foto · hele paard van opzij',
        type: 'photo',
        required: true,
        hint: 'Op 3 m afstand, recht van opzij. Stilstaand op 4 benen.',
      },
      {
        id: 'foto-vooraanzicht',
        label: 'Foto · vooraanzicht',
        type: 'photo',
        required: true,
      },
      {
        id: 'foto-achteraanzicht',
        label: 'Foto · achteraanzicht',
        type: 'photo',
        required: true,
      },
      {
        id: 'foto-huid',
        label: 'Foto · plek waar de klacht zit',
        type: 'photo',
        required: true,
        hint: 'Close-up. Zoom in, niet ver weg.',
      },
      {
        id: 'foto-hoeven',
        label: 'Foto · alle 4 hoeven',
        type: 'photo',
        required: true,
        hint: 'Per hoef vanaf voor + zijaanzicht.',
      },
      {
        id: 'foto-slijmvlies',
        label: 'Foto · slijmvlies (tandvlees of oogwit)',
        type: 'photo',
        hint: 'Optioneel maar enorm waardevol.',
      },
    ],
  },

  /* ============= 10 · CONTROLEREN & VERSTUREN ============= */
  {
    id: 'samenvatting',
    nr: 10,
    title: 'Versturen',
    intro: 'Loop nog een keer door je antwoorden. Aanpassen kan, ook later.',
    minutes: 2,
    icon: 'send',
    sub: 'Controleer & stuur naar Shelley',
    fields: [
      {
        id: 'opmerking-vrij',
        label: 'Iets dat ik moet weten en niet in de vragen paste?',
        type: 'textarea',
      },
      {
        id: 'akkoord-foto',
        label: "Mijn foto's mogen anoniem gebruikt worden in lesmateriaal",
        type: 'radio',
        required: true,
        options: ['ja', 'nee'],
      },
      {
        id: 'akkoord-data',
        label: 'Ik ga akkoord met de privacyverklaring',
        type: 'radio',
        required: true,
        options: ['ja'],
      },
    ],
  },
];

export const TOTAL_MINUTES = INTAKE_SCHEMA.reduce((acc, s) => acc + s.minutes, 0);

export const SECTIONS_BY_ID: Record<string, Section> = Object.fromEntries(
  INTAKE_SCHEMA.map((s) => [s.id, s]),
);

export function getSection(id: string): Section | undefined {
  return SECTIONS_BY_ID[id];
}

/** Convert a single-value answer to its primitive shape. */
export type FieldValue =
  | string
  | number
  | string[]
  | Record<string, string>[] // repeater rows
  | undefined;

/** Whole-section answers keyed by field id. */
export type SectionAnswers = Record<string, FieldValue>;

/** All sections' answers keyed by section id. */
export type IntakeAnswers = Record<string, SectionAnswers>;

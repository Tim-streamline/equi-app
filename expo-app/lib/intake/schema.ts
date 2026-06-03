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
// `showIf`     — field only renders when another field (in the SAME section)
//                has a given value. The special value 'any-checked' matches
//                when the referenced multi field has at least one non-"geen"
//                option selected.
//
// NB: copy for several sections was revised per "Intakeformulier
// aanpassen.pdf" — see CHANGES-from-intakeformulier-aanpassen.md for the
// mapping and the handful of items flagged as best-effort / not (yet)
// supported by the renderer.

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
  /** Render a textarea ~3x taller (for long free-text answers). */
  tall?: boolean;
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

/** Short disclaimer shown at the top of the intake overview screen. */
export const INTAKE_DISCLAIMER_SHORT =
  'Vul dit formulier zo volledig mogelijk in. We kunnen alleen meedenken op ' +
  'basis van wat jij deelt. De adviezen zijn ondersteunend en vervangen geen ' +
  'dierenarts. Bij acute of ernstige klachten neem je altijd contact op met je ' +
  'dierenarts.';

/** Long "belangrijk vooraf" disclaimer shown before the form starts. */
export const INTAKE_DISCLAIMER_LONG =
  'Belangrijk vooraf:\n\n' +
  'Deze app geeft ondersteuning op basis van de informatie die jij invult. ' +
  'Hoe vollediger en eerlijker je antwoorden zijn, hoe gerichter het advies ' +
  'kan zijn.\n\n' +
  'De adviezen in deze app vervangen geen dierenarts of medische behandeling. ' +
  'Bij acute klachten, wonden, extreme benauwdheid, koliekverschijnselen, ' +
  'kreupelheid, koorts of duidelijke achteruitgang neem je altijd contact op ' +
  'met je dierenarts.\n\n' +
  'Door dit formulier in te vullen begrijp je dat het advies gebaseerd is op ' +
  'de aangeleverde informatie en dat je zelf verantwoordelijk blijft voor de ' +
  'keuzes rondom jouw paard.';

export const INTAKE_SCHEMA: Section[] = [
  /* ============= 00 · CONTACT & OPENHEID ============= */
  {
    id: 'contact',
    nr: 0,
    title: 'Contactgegevens',
    intro: 'Laat hieronder weten hoe ik je kan bereiken.',
    minutes: 2,
    icon: 'mail',
    sub: 'E-mail, telefoon, openheid voor aanpassingen',
    fields: [
      {
        id: 'email',
        label: 'E-mailadres',
        type: 'text',
        required: true,
        hint: 'Op dit adres stuur ik je een kopie van je antwoorden en een kopie van je protocol.',
      },
      { id: 'naam-eigenaar', label: 'Jouw naam', type: 'text', required: true },
      {
        id: 'tel-eigenaar',
        label: 'Telefoonnummer',
        type: 'text',
        required: true,
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
        options: ['Ja, helemaal', 'Ik twijfel nog, leg hieronder uit'],
        flagIf: ['Ik twijfel nog, leg hieronder uit'],
      },
      {
        id: 'aanpassingen-toelichting',
        label: 'Toelichting',
        type: 'textarea',
        showIf: {
          'aanpassingen-openheid': ['Ik twijfel nog, leg hieronder uit'],
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
        label: 'Is je paard drachtig of lacterend?',
        type: 'radio',
        options: ['nee', 'drachtig', 'lacterend'],
        showIf: { geslacht: 'merrie' },
        flagIf: ['drachtig', 'lacterend'],
        criticalIf: ['drachtig', 'lacterend'],
      },
      {
        id: 'gewicht',
        label: 'Gewicht',
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
      },
      {
        id: 'foto-paard',
        label: 'Foto van je paard (zijaanzicht vanaf links)',
        type: 'photo',
        hint: 'Voor mijn dossier, zo weet ik gelijk met wie ik werk.',
      },
      {
        id: 'foto-paard-rechts',
        label: 'Foto van je paard (zijaanzicht vanaf rechts)',
        type: 'photo',
      },
    ],
  },

  /* ============= 02 · KLACHT & HULPVRAAG ============= */
  {
    id: 'klacht',
    nr: 2,
    title: 'Klacht & hulpvraag',
    intro: 'Wat speelt er nu? Wees zo open en gedetailleerd mogelijk.',
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
        label: 'Heeft je paard NU klachten?',
        type: 'multi',
        options: [
          'Koorts',
          'Koliek',
          'Staat op reguliere medicatie',
          'Staat op pijnstillers',
          'Anders',
        ],
        criticalIf: ['Koorts', 'Koliek'],
      },
      {
        id: 'acuut-toelichting',
        label: 'Toelichting',
        type: 'textarea',
        hint: 'Welke medicatie / pijnstillers, of licht "anders" toe.',
        showIf: {
          acuut: ['Staat op reguliere medicatie', 'Staat op pijnstillers', 'Anders'],
        },
      },
      {
        id: 'thema',
        label: "Welke thema's spelen mee?",
        type: 'multi',
        options: [
          'Jeuk',
          'Darmen',
          'Staakgedrag',
          'Hoefproblemen',
          'Luchtwegklachten',
          'Afwijkend gedrag',
          'Insuline resistentie',
          'Hoefbevangenheid',
          'Pees- of gewrichtsklachten',
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
        label: "Upload een paar foto's van je paard van de afgelopen 2-5 jaar.",
        type: 'photo',
        hint: "Dit helpt mij om een goed beeld te krijgen van je paard. Max 10 foto's.",
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
        label: 'Heb je ervaring met holistische therapieën?',
        type: 'textarea',
        hint: 'Osteopaat, kruidengeneeskunde, etc. Mag ook "geen".',
      },
      {
        id: 'stressfactoren',
        label: 'Zijn er recente veranderingen?',
        type: 'textarea',
        hint: 'Verhuizing, nieuwe stalgenoten, voerwissel, ander werk, …',
        flagIf: 'non-empty',
      },
      {
        id: 'leuk',
        label: 'Wat vindt je paard heel leuk?',
        type: 'textarea',
        hint: 'Dit helpt me een beeld te krijgen van wat je paard gelukkig maakt.',
      },
    ],
  },

  /* ============= 03 · GESCHIEDENIS ============= */
  {
    id: 'geschiedenis',
    nr: 3,
    title: 'Geschiedenis van je paard',
    intro: '',
    minutes: 5,
    icon: 'history',
    sub: 'De eerste levensjaren, medisch verleden',
    fields: [
      {
        id: 'eerste-maanden',
        label: 'Hoe heeft je paard de eerste maanden van zijn/haar leven doorgebracht?',
        type: 'textarea',
        hint: 'Weet je het niet? Vul dan "onbekend" in.',
      },
      {
        id: 'moeder-voer-huis',
        label: 'Hoe werd de moeder gevoerd en gehuisvest?',
        type: 'textarea',
        hint: 'Weet je het niet? Vul dan "onbekend" in.',
      },
      {
        id: 'moeder-metabolisch',
        label: 'Had de moeder gezondheidsklachten?',
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
        label: 'Hoe werd je paard gevoerd in die periode?',
        type: 'textarea',
        hint: 'Ruwvoer verpakt/onverpakt, bijvoeding, …',
      },
      {
        id: 'spenen-inrijden-symptomen',
        label: 'Heeft je paard in die periode symptomen / ziektes ontwikkeld?',
        type: 'textarea',
        hint: 'Welke, wanneer, hoe behandeld?',
        flagIf: 'non-empty',
      },
      {
        id: 'medische-geschiedenis-volledig',
        label:
          'Wil je de volledige medische geschiedenis uitwerken in chronologische volgorde, zover bij jou bekend?',
        type: 'textarea',
        tall: true,
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
        options: [
          'Tetanus',
          'Influenza',
          'Rhinopneumonie',
          'West Nijl',
          'Geen',
          'Anders, geef toelichting',
        ],
      },
      {
        id: 'vaccinaties-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { vaccinaties: 'Anders, geef toelichting' },
      },
      {
        id: 'vacc-reactie',
        label: 'Reageert je paard op vaccinaties?',
        type: 'multi',
        options: [
          'Nee',
          'Ja, een bult/zwelling op de injectieplek',
          'Ja, enkele dagen wat sloom of minder fit',
          'Ja, pijnlijke/gevoelige injectieplek',
          'Ja, koorts/verhoging',
          'Ja, anders, namelijk',
          'Weet ik niet',
        ],
        flagIf: [
          'Ja, een bult/zwelling op de injectieplek',
          'Ja, enkele dagen wat sloom of minder fit',
          'Ja, pijnlijke/gevoelige injectieplek',
          'Ja, koorts/verhoging',
          'Ja, anders, namelijk',
        ],
      },
      {
        id: 'vacc-reactie-toelichting',
        label: 'Toelichting',
        type: 'text',
        showIf: { 'vacc-reactie': 'Ja, anders, namelijk' },
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
        options: ['vast schema', 'mestonderzoek', 'mix, geef toelichting'],
      },
      {
        id: 'ontworming-strategie-toelichting',
        label: 'Toelichting',
        type: 'textarea',
        showIf: { 'ontworming-strategie': 'mix, geef toelichting' },
      },
      {
        id: 'ontworming-reactie',
        label: 'Reageert je paard op ontwormingen?',
        type: 'multi',
        options: [
          'Nee',
          'Ja, enkele dagen wat sloom of minder fit',
          'Ja, verandering in mest of spijsvertering',
          'Ja, buikgevoeligheid / darmklachten',
          'Ja, verandering in gedrag of gevoeligheid',
          'Ja, anders, namelijk',
          'Weet ik niet',
        ],
        flagIf: [
          'Ja, enkele dagen wat sloom of minder fit',
          'Ja, verandering in mest of spijsvertering',
          'Ja, buikgevoeligheid / darmklachten',
          'Ja, verandering in gedrag of gevoeligheid',
          'Ja, anders, namelijk',
        ],
      },
      {
        id: 'ontworming-reactie-toelichting',
        label: 'Toelichting',
        type: 'text',
        showIf: { 'ontworming-reactie': 'Ja, anders, namelijk' },
      },

      { id: 'sec-tand', label: 'Gebit en tandarts', type: 'sectionhead' },
      {
        id: 'tandarts-freq',
        label: 'Hoe vaak wordt het gebit van jouw paard gecontroleerd?',
        type: 'radio',
        required: true,
        options: [
          'Elke 6 maanden of vaker',
          'Ongeveer 1x per jaar',
          'Ongeveer 1x per 1,5–2 jaar',
          'Minder vaak dan 1x per 2 jaar',
          'Alleen bij klachten / wanneer nodig',
          'Nog nooit / onbekend',
        ],
        flagIf: ['Minder vaak dan 1x per 2 jaar', 'Alleen bij klachten / wanneer nodig', 'Nog nooit / onbekend'],
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
        label: 'Door wie wordt het gebit behandeld?',
        type: 'radio',
        required: true,
        options: [
          'Dierenarts',
          'Gediplomeerd paardentandarts / gebitsverzorger',
          'Anders, namelijk',
        ],
      },
      {
        id: 'tandarts-behandelaar-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'tandarts-methode': 'Anders, namelijk' },
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
        label: 'Door wie en hoe vaak wordt je paard bekapt / beslagen?',
        type: 'text',
        required: true,
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
          'hoefbevangenheid',
          'NPA',
          'geen',
          'anders',
        ],
        flagIf: [
          'afwijkende hoefstand',
          'white line disease',
          'rotstraal',
          'hoefkanker',
          'regelmatig hoefzweren',
          'hoefbevangenheid',
          'NPA',
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
        options: ['aangetoond door dierenarts', 'vermoeden van mij', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond door dierenarts', 'vermoeden van mij'],
      },
      {
        id: 'ir-attest',
        label: 'Onderzoeksresultaten urine-/bloedonderzoek',
        type: 'file',
        hint: 'Optioneel — upload het bestand indien beschikbaar.',
        showIf: { 'ir-status': 'aangetoond door dierenarts' },
      },
      {
        id: 'ems-status',
        label: 'EMS (Equine Metabolisch Syndroom)',
        type: 'radio',
        required: true,
        options: ['aangetoond door dierenarts', 'vermoeden van mij', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond door dierenarts', 'vermoeden van mij'],
      },
      {
        id: 'ems-attest',
        label: 'Onderzoeksresultaten urine-/bloedonderzoek',
        type: 'file',
        hint: 'Optioneel — upload het bestand indien beschikbaar.',
        showIf: { 'ems-status': 'aangetoond door dierenarts' },
      },
      {
        id: 'kpu-status',
        label: 'KPU (Kryptopyrrolurie)',
        type: 'radio',
        required: true,
        options: ['aangetoond uit urine onderzoek', 'vermoeden van mij', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond uit urine onderzoek', 'vermoeden van mij'],
      },
      {
        id: 'kpu-attest',
        label: 'Onderzoeksresultaten urine-/bloedonderzoek',
        type: 'file',
        hint: 'Optioneel — upload het bestand indien beschikbaar.',
        showIf: { 'kpu-status': 'aangetoond uit urine onderzoek' },
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
        id: 'maag-symptomen',
        label: 'Herken je één of meerdere van onderstaande signalen bij jouw paard?',
        type: 'multi',
        hint: 'Alles aanvinken wat je herkent.',
        options: [
          'Geen van onderstaande',
          'veel gapen / flehmen / slikken',
          'Tandenknarsen',
          'Gevoelig bij aansingelen of aanraken van de buik/flanken',
          'Geïrriteerd, prikkelbaar of sneller boos/agressief',
          'Onrust, spanning of moeilijk ontspannen',
          'Minder eetlust / kieskeurig eten',
          'Langzaam eten of stoppen tijdens het eten',
          'Slechter presteren / weerstand bij training of rijden',
          'Mestveranderingen (bijv. mestwater, wisselende mest, dunne mest)',
          'Vermageren of moeite met op gewicht blijven',
          'Terugkerende koliekachtige klachten',
          'Veel liggen / ongemak tonen',
          'Houdings- of gedragsverandering rondom voer, training of stalrust',
          'Geen duidelijke klachten, maar ik vermoed toch maagongemak',
        ],
        flagIf: 'any',
      },
      {
        id: 'maag-medicatie',
        label: 'Heeft je paard ooit maagmedicatie gehad?',
        type: 'radio',
        options: ['ja', 'nee'],
        flagIf: ['ja'],
      },
      {
        id: 'maag-med-welke',
        label: 'Welke maagmedicatie?',
        type: 'text',
        showIf: { 'maag-medicatie': 'ja' },
      },
      {
        id: 'maag-med-wanneer',
        label: 'Wanneer was dit precies?',
        type: 'text',
        showIf: { 'maag-medicatie': 'ja' },
      },
      {
        id: 'maag-med-hoelang',
        label: 'Hoe lang heb je dit gegeven?',
        type: 'text',
        showIf: { 'maag-medicatie': 'ja' },
      },
      {
        id: 'maag-med-opbouw',
        label: 'Heb je op- of afgebouwd?',
        type: 'text',
        showIf: { 'maag-medicatie': 'ja' },
      },
      {
        id: 'maag-med-effect',
        label: 'Zag je effect van de medicatie? Omschrijf wat je zag indien ja.',
        type: 'textarea',
        showIf: { 'maag-medicatie': 'ja' },
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
        label:
          'Heeft jouw paard probiotica gekregen of producten met gist / yeast / Yeasacc / Saccharomyces cerevisiae?',
        type: 'radio',
        required: true,
        hint:
          'Controleer ook de ingrediëntenlijst van huidige voeding, balancers en supplementen.',
        options: ['Nee', 'Ja', 'Weet ik niet'],
        flagIf: ['Ja'],
      },
      {
        id: 'probiotica-welke',
        label: 'Welk product / welke producten?',
        type: 'text',
        hint: 'Omschrijf de exacte productnaam.',
        showIf: { 'probiotica-geschiedenis': 'Ja' },
      },
      {
        id: 'probiotica-wanneer',
        label: 'Wanneer kreeg jouw paard dit?',
        type: 'radio',
        options: [
          'Krijgt dit momenteel',
          'In de afgelopen maand',
          '1–6 maanden geleden',
          '6–12 maanden geleden',
          'Meer dan 1 jaar geleden',
        ],
        showIf: { 'probiotica-geschiedenis': 'Ja' },
      },
      {
        id: 'probiotica-hoelang',
        label: 'Hoe lang kreeg jouw paard dit ongeveer?',
        type: 'radio',
        options: [
          'Korter dan 2 weken',
          '2–6 weken',
          '1–3 maanden',
          '3–6 maanden',
          '6–12 maanden',
          'Langer dan 1 jaar',
          'Weet ik niet',
        ],
        showIf: { 'probiotica-geschiedenis': 'Ja' },
      },
      {
        id: 'probiotica-toelichting',
        label: 'Eventuele toelichting (dosering, reden van gebruik)',
        type: 'textarea',
        showIf: { 'probiotica-geschiedenis': 'Ja' },
      },

      { id: 'sec-medicatie', label: 'Medicatie', type: 'sectionhead' },
      {
        id: 'medicatie-nu',
        label: 'Gebruikt jouw paard momenteel medicatie?',
        type: 'radio',
        required: true,
        options: ['Nee', 'Ja'],
        flagIf: ['Ja'],
      },
      {
        id: 'medicatie-nu-welke',
        label: 'Welke medicatie gebruikt jouw paard momenteel?',
        type: 'textarea',
        hint:
          "Denk bijvoorbeeld aan NSAID's, maagmedicatie, corticosteroïden, PPID-medicatie, luchtwegmedicatie, antibiotica etc.",
        showIf: { 'medicatie-nu': 'Ja' },
      },
      {
        id: 'medicatie-nu-naam',
        label: 'Medicatie / productnaam',
        type: 'text',
        showIf: { 'medicatie-nu': 'Ja' },
      },
      {
        id: 'medicatie-nu-reden',
        label: 'Reden van gebruik',
        type: 'text',
        showIf: { 'medicatie-nu': 'Ja' },
      },
      {
        id: 'medicatie-nu-dosering',
        label: 'Dosering (indien bekend)',
        type: 'text',
        showIf: { 'medicatie-nu': 'Ja' },
      },
      {
        id: 'medicatie-nu-sinds',
        label: 'Sinds wanneer',
        type: 'text',
        showIf: { 'medicatie-nu': 'Ja' },
      },
      {
        id: 'medicatie-recent',
        label: 'Heeft jouw paard in de afgelopen 2-5 jaar medicatie gekregen?',
        type: 'radio',
        required: true,
        options: ['Nee', 'Ja, incidenteel', 'Ja, meerdere keren of langdurig', 'Weet ik niet'],
        flagIf: ['Ja, incidenteel', 'Ja, meerdere keren of langdurig'],
      },
      {
        id: 'medicatie-ooit-welke',
        label: 'Welke medicatie heeft jouw paard ooit gekregen?',
        type: 'multi',
        hint: 'Meerdere antwoorden mogelijk.',
        options: [
          'Antibiotica',
          'Ontstekingsremmers / pijnstilling (bijv. Bute, Meloxicam, Equioxx)',
          'Corticosteroïden / prednison / dexamethason',
          'Maagmedicatie (bijv. omeprazol)',
          'Ventipulmin',
          'PPID / Cushing medicatie (bijv. Prascend)',
          'Sedatie / kalmeringsmiddelen',
          'Hormoon- of vruchtbaarheidsmedicatie',
          'Antischimmel / antiparasitaire medicatie',
          'Anders, namelijk',
        ],
        showIf: { 'medicatie-recent': ['Ja, incidenteel', 'Ja, meerdere keren of langdurig'] },
      },
      {
        id: 'medicatie-ooit-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'medicatie-ooit-welke': 'Anders, namelijk' },
      },
      {
        id: 'medicatie-ooit-details',
        label: 'Geef dit graag per medicatie aan',
        type: 'repeater',
        showIf: { 'medicatie-recent': ['Ja, incidenteel', 'Ja, meerdere keren of langdurig'] },
        sub: [
          { id: 'naam', label: 'Naam medicatie', type: 'text' },
          { id: 'klacht', label: 'Vanwege welke klacht/symptoom ingezet?', type: 'text' },
          { id: 'wanneer', label: 'Wanneer ongeveer gegeven (jaartal/maand)', type: 'text' },
          { id: 'hoelang', label: 'Hoe lang gegeven', type: 'text' },
          { id: 'reactie', label: 'Reageerde jouw paard erop? (ja / nee / weet ik niet)', type: 'text' },
          { id: 'reactie-hoe', label: 'Zo ja, hoe?', type: 'textarea' },
        ],
      },
    ],
  },

  /* ============= 05 · VOER & RUWVOER ============= */
  {
    id: 'voer',
    nr: 5,
    title: 'Voer & ruwvoer',
    intro: 'Wees specifiek in merknamen en hoeveelheden.',
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
        id: 'hooi-verpakking-toelichting',
        label: 'Geef toelichting over de verhoudingen en wanneer wat precies gegeven wordt',
        type: 'textarea',
        showIf: { 'hooi-verpakking': 'mix' },
      },
      {
        id: 'hooi-omschrijving',
        label: 'Hoe omschrijf je het ruwvoer?',
        type: 'multi',
        required: true,
        hint: 'Vink alles aan wat relevant is.',
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
        options: ['constant', 'wisselt per baal', 'wisselt per levering'],
        flagIf: ['wisselt per baal', 'wisselt per levering'],
      },
      {
        id: 'hooi-herkomst',
        label: 'Waar komt het ruwvoer van jouw paard vandaan?',
        type: 'radio',
        required: true,
        options: [
          'Eigen land / eigen productie',
          'Lokaal geproduceerd',
          'Ingekocht binnen eigen land',
          'Geïmporteerd / afkomstig uit het buitenland',
          'Wisselende herkomst / onbekend',
        ],
      },
      {
        id: 'hooi-zelfde-perceel',
        label: 'Is het ruwvoer meestal afkomstig van hetzelfde perceel / dezelfde leverancier?',
        type: 'radio',
        options: ['Ja, meestal wel', 'Nee, wisselt regelmatig', 'Weet ik niet'],
      },
      {
        id: 'hooi-teelt',
        label: 'Weet je iets over het land van herkomst / de teeltomstandigheden?',
        type: 'textarea',
        hint: 'Bijv. kruidenrijk, bemest, intensief beheerd, natuurgrond, irrigatie, onbekend.',
      },
      {
        id: 'hooi-productie',
        label: 'Hoe wordt het hooi van jouw paard geproduceerd / gewonnen?',
        type: 'multi',
        hint: 'Vink alles aan wat relevant is.',
        options: [
          '1e snede',
          '2e snede',
          '3e snede of later',
          'Kruidenrijk / natuurhooi',
          'Productiegras / landbouwgrasland',
          'Bemest land',
          'Onbemest / extensief beheerd land',
          'Biologisch',
          'Weet ik niet',
        ],
      },
      {
        id: 'hooi-aanbod',
        label: 'Hoe wordt het hooi gevoerd?',
        type: 'multi',
        required: true,
        options: [
          'Los op de grond',
          'Los in bakken',
          'Slowfeeders (mazen <3 cm)',
          'Hooinetten (mazen >3 cm)',
          'Hooruif (metaal)',
          'Anders / combinatie, namelijk',
        ],
      },
      {
        id: 'hooi-aanbod-anders',
        label: 'Anders / combinatie, namelijk',
        type: 'text',
        showIf: { 'hooi-aanbod': 'Anders / combinatie, namelijk' },
      },
      {
        id: 'hooi-porties',
        label: 'Hoe vaak krijgt jouw paard hooi?',
        type: 'radio',
        hint: 'Hoeveel porties.',
        options: [
          'Continu / ad libitum',
          'Meerdere keren per dag',
          '3x per dag',
          '2x per dag',
          '1x per dag',
          'Wisselend',
        ],
      },
      {
        id: 'hooi-pauze-incl-nacht',
        label: 'Heeft jouw paard periodes zonder ruwvoer (inclusief de nachten!)?',
        type: 'radio',
        options: ['Nee, nooit', 'Ja, soms', 'Ja, regelmatig', 'Weet ik niet'],
        flagIf: ['Ja, soms', 'Ja, regelmatig'],
      },
      {
        id: 'hooi-pauze-uren',
        label: 'Hoeveel uur achter elkaar heeft jouw paard meestal geen toegang tot ruwvoer?',
        type: 'radio',
        options: [
          'Minder dan 1 uur',
          '1–2 uur',
          '2–4 uur',
          '4–6 uur',
          '6–8 uur',
          'Meer dan 8 uur',
          'Wisselend / moeilijk in te schatten',
        ],
        showIf: { 'hooi-pauze-incl-nacht': ['Ja, soms', 'Ja, regelmatig'] },
      },
      {
        id: 'hooi-pauze-langste',
        label: "Wat is de langste periode zonder ruwvoer die jouw paard doorgaans heeft (inclusief 's nachts)?",
        type: 'number',
        unit: 'uur',
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
        options: ['eerst ruwvoer', 'eerst krachtvoer', 'tegelijkertijd'],
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
        hint:
          'Pak een paar plukjes hooi uit de baal (liefst uit verschillende plekken), spreid deze los uit op een neutrale, egale ondergrond en maak een duidelijke foto van dichtbij.',
      },

      { id: 'sec-voordroog', label: 'Voordroog en kuil', type: 'sectionhead' },
      {
        id: 'voordroog-verleden',
        label: 'Heeft je paard ooit ruwvoer verpakt in plastic gegeten? (voordroog of kuil)',
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

      { id: 'sec-graszaadhooi', label: 'Graszaadhooi', type: 'sectionhead' },
      {
        id: 'graszaadhooi',
        label: 'Krijgt jouw paard graszaadhooi?',
        type: 'radio',
        options: ['Nee', 'Ja', 'Weet ik niet'],
      },
      {
        id: 'graszaadhooi-aandeel',
        label: 'Ongeveer hoeveel van het totale ruwvoer bestaat uit graszaadhooi?',
        type: 'radio',
        options: ['<25%', '25–50%', '50–75%', '>75%', '100%', 'Weet ik niet'],
        showIf: { graszaadhooi: 'Ja' },
      },
      {
        id: 'graszaadhooi-cert',
        label: 'Is het graszaadhooi gecertificeerd geschikt als paardenvoer?',
        type: 'radio',
        options: ['Ja', 'Nee', 'Weet ik niet'],
        showIf: { graszaadhooi: 'Ja' },
      },

      { id: 'sec-stro', label: 'Stro', type: 'sectionhead' },
      {
        id: 'stro-aanwezig',
        label: 'Eet jouw paard stro?',
        type: 'radio',
        required: true,
        hint: 'Denk hierbij zowel aan stro als bodembedekking als aan bewust gevoerd stro.',
        options: [
          'Nee, mijn paard eet geen / nauwelijks stro',
          'Ja, mijn paard knabbelt af en toe aan stro als bodembedekking',
          'Ja, mijn paard eet duidelijk mee van de stro-bodembedekking',
          'Ja, mijn paard krijgt stro bewust bijgevoerd als onderdeel van het rantsoen',
          'Ja, zowel stro-bodembedekking als bewust bijgevoerd stro',
          'Weet ik niet',
        ],
      },
      {
        id: 'stro-type',
        label: 'Om welk type stro gaat het?',
        type: 'radio',
        options: ['Tarwestro', 'Gerstestro', 'Haverstro', 'Anders, namelijk', 'Weet ik niet'],
        showIf: {
          'stro-aanwezig': [
            'Ja, mijn paard knabbelt af en toe aan stro als bodembedekking',
            'Ja, mijn paard eet duidelijk mee van de stro-bodembedekking',
            'Ja, mijn paard krijgt stro bewust bijgevoerd als onderdeel van het rantsoen',
            'Ja, zowel stro-bodembedekking als bewust bijgevoerd stro',
          ],
        },
      },
      {
        id: 'stro-type-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'stro-type': 'Anders, namelijk' },
      },
      {
        id: 'stro-biologisch',
        label: 'Is het stro biologisch?',
        type: 'radio',
        options: ['Ja', 'Nee'],
        showIf: {
          'stro-aanwezig': [
            'Ja, mijn paard knabbelt af en toe aan stro als bodembedekking',
            'Ja, mijn paard eet duidelijk mee van de stro-bodembedekking',
            'Ja, mijn paard krijgt stro bewust bijgevoerd als onderdeel van het rantsoen',
            'Ja, zowel stro-bodembedekking als bewust bijgevoerd stro',
          ],
        },
      },
      {
        id: 'stro-hoeveelheid',
        label: 'Hoeveel stro eet jouw paard ongeveer?',
        type: 'number',
        unit: 'kg',
        showIf: {
          'stro-aanwezig': [
            'Ja, mijn paard knabbelt af en toe aan stro als bodembedekking',
            'Ja, mijn paard eet duidelijk mee van de stro-bodembedekking',
            'Ja, mijn paard krijgt stro bewust bijgevoerd als onderdeel van het rantsoen',
            'Ja, zowel stro-bodembedekking als bewust bijgevoerd stro',
          ],
        },
      },

      { id: 'sec-kracht', label: 'Krachtvoer en bijvoer', type: 'sectionhead' },
      {
        id: 'voer-gewisseld',
        label: 'Ben je recent gewisseld van (ruw)voer?',
        type: 'radio',
        required: true,
        options: ['ja, <3 maanden', 'ja, <6 maanden', 'nee'],
        flagIf: ['ja, <3 maanden'],
      },
      {
        id: 'voer-gewisseld-toelichting',
        label: 'Toelichting voerwissel — van wat precies?',
        type: 'textarea',
        hint: 'Wat kreeg ze hiervoor en wanneer overgegaan?',
        showIf: { 'voer-gewisseld': ['ja, <3 maanden', 'ja, <6 maanden'] },
      },
      {
        id: 'huidige-bijvoeding',
        label: 'Welke bijvoeding geef je allemaal OP DIT MOMENT en in welke hoeveelheden?',
        type: 'repeater',
        required: true,
        hint: 'Merknamen + type EXACT benoemen.',
        sub: [
          { id: 'product', label: 'Merk + exacte productnaam', type: 'text' },
          { id: 'hoeveelheid', label: 'Hoeveelheid per dag', type: 'text' },
        ],
      },
      {
        id: 'bijvoeding-historie',
        label: 'Welke bijvoeding heeft jouw paard in de afgelopen 2–5 jaar gekregen?',
        type: 'repeater',
        required: true,
        hint:
          "Exclusief supplementen, die komen later. Denk aan muesli's, brokken, balancers, bietenpulp, luzerne, mash, gehakseld ruwvoer, etc. Graag ook kortdurende periodes meenemen.",
        sub: [
          { id: 'product', label: 'Merk + exacte productnaam', type: 'text' },
          { id: 'wanneer', label: 'Ongeveer wanneer (jaartal/periode)', type: 'text' },
          { id: 'hoelang', label: 'Hoe lang gevoerd (weken/maanden/jaren)', type: 'text' },
        ],
      },
      {
        id: 'balancer',
        label: 'Welke mineralenvoeding / balancer krijgt je paard op dit moment?',
        type: 'textarea',
        required: true,
        hint: 'Merk, type, hoeveelheid.',
      },
      {
        id: 'mineralen-toegang',
        label: 'Heeft je paard verder nog toegang tot andere mineralen?',
        type: 'multi',
        required: true,
        hint: 'Denk aan mineralenbuffet, likstenen etc.',
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
        label: "Krijgt jouw paard snacks, tussendoortjes of extra's buiten het normale voer om?",
        type: 'radio',
        required: true,
        hint: 'Denk óók aan kleine dingen.',
        options: ['Nee', 'Ja'],
      },
      {
        id: 'snacks-welke',
        label: 'Wat krijgt jouw paard allemaal?',
        type: 'multi',
        hint: 'Vink alles aan wat van toepassing is.',
        options: [
          'Paardensnoepjes / treats',
          'Wortels',
          'Appels',
          'Brood / crackers / menselijke etensresten',
          'Likemmers / stal-likproducten / "Licki" / boredom breakers',
          'Extra handjes voer / losse brokjes tussendoor',
          'Kruiden / planten / takken als snack',
          'Anders, namelijk',
        ],
        showIf: { 'snacks-aanwezig': 'Ja' },
      },
      {
        id: 'snacks-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'snacks-welke': 'Anders, namelijk' },
      },
      {
        id: 'snacks-detail',
        label: 'Graag per product vermelden',
        type: 'repeater',
        showIf: { 'snacks-aanwezig': 'Ja' },
        sub: [
          { id: 'product', label: 'Wat precies (merk + productnaam indien relevant)', type: 'text' },
          { id: 'frequentie', label: 'Hoe vaak (dagelijks / wekelijks / af en toe)', type: 'text' },
          { id: 'hoeveel', label: 'Ongeveer hoeveel', type: 'text' },
        ],
      },
      {
        id: 'huidig-extra',
        label: 'Benoem hier alle HUIDIGE supplementen',
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
        label: 'Welke supplementen heeft jouw paard in de afgelopen 2–5 jaar gekregen?',
        type: 'repeater',
        required: true,
        hint:
          'Denk aan: vitaminen/mineralen, magnesium, probiotica, kruiden, darmproducten, lever-/nierondersteuning, olie, elektrolyten, aminozuren, hoef-, huid-, gewrichts-, luchtweg- of maagproducten, etc. Zet zo volledig mogelijk op een rijtje.',
        sub: [
          { id: 'product', label: 'Merk + exacte productnaam', type: 'text' },
          { id: 'wanneer', label: 'Ongeveer wanneer (jaartal/periode)', type: 'text' },
          { id: 'hoelang', label: 'Hoe lang gegeven (weken/maanden/jaren)', type: 'text' },
        ],
      },
    ],
  },

  /* ============= 06 · WATER & UITSCHEIDING ============= */
  {
    id: 'water',
    nr: 6,
    title: 'Water & uitscheiding',
    intro: '',
    minutes: 5,
    icon: 'droplet',
    sub: 'Vochtinname, urine, mest',
    fields: [
      {
        id: 'water-type',
        label: 'Wat voor water drinkt je paard?',
        type: 'multi',
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
        label: 'Is er een wateranalyse aanwezig?',
        type: 'file',
        hint: 'Upload PDF.',
      },
      {
        id: 'water-aanbod',
        label: 'Hoe wordt water aangeboden?',
        type: 'multi',
        required: true,
        hint: 'Vink alles aan wat van toepassing is.',
        options: [
          'Automatische drinkbak',
          'Emmer(s)',
          'Grote bak / ton / kuip',
          'Drinkbak in stal',
          'Drinkbak buiten / paddock / weide',
          'Natuurlijke waterbron (sloot, beek, vijver, etc.)',
          'Anders, namelijk',
        ],
      },
      {
        id: 'water-aanbod-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'water-aanbod': 'Anders, namelijk' },
      },
      {
        id: 'water-toegang',
        label: 'Heeft jouw paard altijd toegang tot water?',
        type: 'radio',
        required: true,
        options: ['Ja, continu', 'Meestal wel', 'Nee, niet altijd', 'Weet ik niet'],
        flagIf: ['Nee, niet altijd'],
      },
      {
        id: 'water-verversen',
        label: 'Wordt het water regelmatig ververst / de bakken schoongemaakt?',
        type: 'radio',
        options: ['Dagelijks', 'Meerdere keren per week', 'Minder vaak', 'Weet ik niet'],
        flagIf: ['Minder vaak'],
      },
      {
        id: 'water-kwaliteit',
        label: 'Hoe omschrijf je de kwaliteit van het water?',
        type: 'multi',
        required: true,
        hint: 'Vink alles aan wat relevant is.',
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
        label: 'Drinkt jouw paard naar jouw idee voldoende?',
        type: 'radio',
        required: true,
        options: [
          'Ja',
          'Twijfelachtig / wisselend',
          'Nee / drinkt opvallend weinig',
          'Drinkt opvallend veel',
          'Weet ik niet',
        ],
        flagIf: ['Twijfelachtig / wisselend', 'Nee / drinkt opvallend weinig', 'Drinkt opvallend veel'],
      },

      { id: 'sec-uit', label: 'Urine en mest', type: 'sectionhead' },
      {
        id: 'urine',
        label: 'Hoe is de urine van je paard?',
        type: 'multi',
        options: ['helder', 'gelig', 'oranje', 'troebel', 'normaal volume', 'minder', 'meer'],
        flagIf: ['oranje', 'troebel', 'minder', 'meer'],
      },
      {
        id: 'mest-freq',
        label: 'Wat is de mest frequentie per dag?',
        type: 'radio',
        options: ['<6×', '6–10×', '10–14×', '>14×', 'weet ik niet'],
      },
      {
        id: 'mest-geur',
        label: 'Valt de geur van de mest van jouw paard op?',
        type: 'multi',
        options: [
          'Nee, ruikt normaal / niet opvallend',
          'Ja, zuur / fermentatieachtig',
          'Ja, sterk / scherper ruikend dan van andere paarden',
          'Ja, rottingsachtig / onaangenaam',
          'Ja, wisselend',
          'Anders, namelijk',
          'Weet ik niet',
        ],
        flagIf: [
          'Ja, zuur / fermentatieachtig',
          'Ja, sterk / scherper ruikend dan van andere paarden',
          'Ja, rottingsachtig / onaangenaam',
          'Ja, wisselend',
        ],
      },
      {
        id: 'mest-geur-anders',
        label: 'Anders, namelijk',
        type: 'text',
        showIf: { 'mest-geur': 'Anders, namelijk' },
      },
      {
        id: 'mest-kleur',
        label: 'Kleur van mest',
        type: 'multi',
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
        label: "Upload foto's van de mest van je paard",
        type: 'photo',
        required: true,
        hint:
          "Maak 3 foto's: (1) van bovenaf van de mesthoop; (2) één mestbal voorzichtig een beetje open zodat de binnenkant zichtbaar is, close-up; (3) één verse mestbal geplet met je voet op een harde, schone ondergrond (geen zand/stro), close-up. Gebruik verse mest, daglicht, en stuur enkel scherpe foto's.",
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

      {
        id: 'sec-hoef-upload',
        label: 'Indien je hulpvraag hoefgerelateerd is',
        type: 'sectionhead',
      },
      {
        id: 'foto-hoef-instructie',
        label: "Upload van iedere hoef 3 foto's (12 in totaal)",
        type: 'photo',
        hint:
          "Per hoef: (1) onderzijde / zool, (2) achterzijde, (3) zijaanzicht. De hoeven moeten zeer goed schoongemaakt zijn — verwijder alle modder, zand, mest en losse vervuiling (gebruik indien nodig een staalborstel). Zorg voor goed licht en scherpe foto's, recht van voren / van opzij.",
      },
      {
        id: 'foto-hoef-lv',
        label: 'Foto · linkervoorhoef (zool, achterzijde, zijaanzicht)',
        type: 'photo',
      },
      {
        id: 'foto-hoef-rv',
        label: 'Foto · rechtervoorhoef (zool, achterzijde, zijaanzicht)',
        type: 'photo',
      },
      {
        id: 'foto-hoef-la',
        label: 'Foto · linkerachterhoef (zool, achterzijde, zijaanzicht)',
        type: 'photo',
      },
      {
        id: 'foto-hoef-ra',
        label: 'Foto · rechterachterhoef (zool, achterzijde, zijaanzicht)',
        type: 'photo',
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

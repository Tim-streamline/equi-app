// Mock data ported verbatim from prototype_equinova/Data.jsx

export const HORSE = {
  name: 'Nova',
  breed: 'Friese kruising',
  age: 9,
  sex: 'merrie',
  weight: 540,
  stall: 'Manege De Hoeve · Box 4',
  focus: ['Jeukklachten', 'Spijsvertering'],
};

export type ProtocolItem = { id: number; label: string; done: boolean; meta: string };
export const TODAY_PROTOCOL: ProtocolItem[] = [
  { id: 1, label: '1 el brandnetel door ruwvoer', done: true,  meta: 'Ochtendvoer' },
  { id: 2, label: '1 el lijnzaad door ruwvoer',   done: false, meta: 'Ochtendvoer' },
  { id: 3, label: 'Foto van mest in app loggen',  done: false, meta: 'Na ochtendmest' },
  { id: 4, label: '5 min borstelen rond manen',   done: false, meta: 'Vóór beweging' },
];

export const FOCUS_OPTIONS = [
  { id: 'jeuk',  ic: '🌿', t: 'Jeukklachten',    d: 'Huid, manen, staart' },
  { id: 'staak', ic: '🐎', t: 'Staakgedrag',      d: 'Onder zadel of in stal' },
  { id: 'darm',  ic: '💧', t: 'Darmproblemen',    d: 'Mest, kolieken, gas' },
  { id: 'prev',  ic: '✨', t: 'Preventief',        d: 'Geen klachten, wel meer weten' },
  { id: 'and',   ic: '✦',  t: 'Iets anders',      d: 'Vertel het in een vrij veld' },
];

export const SEASONAL = {
  month: 'mei',
  tip: 'Brandnetel staat nu volop. Pluk de jonge blaadjes en geef ze vers door het ruwvoer — een natuurlijke ondersteuning bij de voorjaarsrui en milde jeuk.',
};

export const LIBRARY_FEATURED = {
  id: 'brandnetel',
  kind: 'Kruid',
  t: 'Brandnetel — 5 minuten over de juiste dosering',
  dur: '5 min · Video',
  desc: 'Hoeveel, hoe vaak en wat juist te vermijden.',
};

export const LIBRARY_LIST = [
  { id: 'lijnzaad',   kind: 'Voeding',  t: 'Lijnzaad: doseren in 7 dagen',           dur: '8 min · Artikel' },
  { id: 'mest-score', kind: 'Diagnose', t: 'Lees de mest van je paard',              dur: '6 min · Video' },
  { id: 'darmen',     kind: 'Cursus',   t: 'Darmen Cursus — Hoofdstuk 3 · 4 lessen', dur: '42 min · Video' },
  { id: 'locatie',    kind: 'Locatie',  t: 'In balans bij locatiewissel',            dur: '4 weken · Programma' },
  { id: 'hoefb',      kind: 'Symptoom', t: 'Hoefbevangenheid — de eerste signalen',  dur: '7 min · Artikel' },
];

export type CommunityPost = {
  id: number; name: string; av: string; when: string; q: string;
  reactions: { likes: number; replies: number };
  hasExpert?: boolean;
};
export const COMMUNITY: CommunityPost[] = [
  { id: 1, name: 'Esther M.', av: 'E', when: '2 u',       q: 'Mijn ruin krabt zijn manen al weken open. Voeding al aangepast, geen verbetering. Iemand ervaring met brandnetel-protocol?', reactions: { likes: 18, replies: 7 }, hasExpert: true },
  { id: 2, name: 'Jolien K.', av: 'J', when: '5 u',       q: 'Iemand een goede bron voor onverpakt biologisch hooi in regio Utrecht? De huidige leverancier is verhuisd.', reactions: { likes: 4, replies: 12 } },
  { id: 3, name: 'Marit',     av: 'M', when: 'gisteren',  q: 'Wat geven jullie tijdens een locatiewissel? Volgende week verhuizing en wil voorbereid zijn.', reactions: { likes: 9, replies: 21 }, hasExpert: true },
];

export type IngredientTag = 'good' | 'warn' | 'danger';
export const SCAN_RESULT = {
  product: 'Pavo Care 4 Life — supplement',
  brand: 'Pavo',
  score: 62,
  rating: 'Matig',
  ingredients: [
    { nm: 'Lijnzaad',          tag: 'good'   as IngredientTag, ds: 'Goede bron van omega-3 — past in een holistisch voerplan.' },
    { nm: 'Bierdrab',          tag: 'good'   as IngredientTag, ds: 'Natuurlijke B-vitaminen en aminozuren.' },
    { nm: 'Mout-extract',      tag: 'warn'   as IngredientTag, ds: 'Bevat suikers. Niet ideaal voor insuline-gevoelige paarden.' },
    { nm: 'Vit. C (synth.)',   tag: 'warn'   as IngredientTag, ds: 'Synthetische toevoeging — overweeg natuurlijke bron.' },
    { nm: 'Saccharose (E473)', tag: 'danger' as IngredientTag, ds: 'Toegevoegde suiker — vermijd bij metabole problemen.' },
  ],
  advice: 'Niet ideaal voor Nova vanwege haar darmgevoeligheid. Bekijk het 100% natuurlijke alternatief in de bibliotheek.',
};

export const PROTOCOL_META = {
  horseName: 'Nova',
  subtitleAnalyse: 'KWPN merrie · Jeuk / Zomereczeem',
  subtitleProtocol: 'Week 3 van 8 · Fase 1 actief',
  subtitleCalendar: 'Mei 2026',
};

export const PROTOCOL_ANALYSE = {
  cause:
    'Nova heeft tekenen van een overbelast immuunsysteem door een verstoorde darmflora. De jeuk is niet het echte probleem. Het is een signaal van binnenuit.',
  advice: [
    { id: 'voeding',    icon: 'leaf'  as const, t: 'Voeding',    d: 'Krachtvoer met granen vervangen. Ruwvoer onbeperkt. Lijnzaad toevoegen.' },
    { id: 'management', icon: 'run'   as const, t: 'Management', d: 'Minimaal 6 uur bewegingsvrijheid per dag. Nachtbeweging indien mogelijk.' },
    { id: 'training',   icon: 'horse' as const, t: 'Training',   d: 'Eerste 4 weken lichte belasting. Geen wedstrijdvoorbereiding tijdens fase 1.' },
  ],
};

export type PhaseState = 'done' | 'active' | 'upcoming';
export type Phase = { id: string; t: string; state: PhaseState; chip: string; items?: string[] };
export const PROTOCOL_PHASES: Phase[] = [
  { id: 'prep',  t: 'Voorbereiding',           state: 'done',     chip: 'Klaar' },
  { id: 'darm',  t: 'Fase 1 — Darmen',          state: 'active',   chip: 'Actief · wk 1–4', items: [
      '1 el brandnetel door ruwvoer (ochtend)',
      '1 el lijnzaad door ruwvoer (ochtend)',
      'Krachtvoer met granen weglaten',
      'Mest observeren en noteren',
    ] },
  { id: 'lever', t: 'Fase 2 — Lever en nieren', state: 'upcoming', chip: 'Vanaf wk 5' },
  { id: 'huid',  t: 'Fase 3 — Huid',            state: 'upcoming', chip: 'Vanaf wk 7' },
];

export type DayState = 'done' | 'today' | 'upcoming' | 'empty';
export type CalDay = { d: number; s: DayState };
export const PROTOCOL_CALENDAR = {
  monthLabel: 'Mei 2026',
  todayDay: 10,
  weeks: [
    [{d:1,s:'done'},{d:2,s:'done'},{d:3,s:'done'},{d:4,s:'done'},{d:5,s:'done'},{d:6,s:'done'},{d:7,s:'done'}],
    [{d:8,s:'done'},{d:9,s:'done'},{d:10,s:'today'},{d:11,s:'upcoming'},{d:12,s:'upcoming'},{d:13,s:'upcoming'},{d:14,s:'upcoming'}],
    [{d:15,s:'upcoming'},{d:16,s:'upcoming'},{d:17,s:'upcoming'},{d:18,s:'upcoming'},{d:19,s:'upcoming'},{d:20,s:'upcoming'},{d:21,s:'empty'}],
    [{d:22,s:'empty'},{d:23,s:'empty'},{d:24,s:'empty'},{d:25,s:'empty'},{d:26,s:'empty'},{d:27,s:'empty'},{d:28,s:'empty'}],
    [{d:29,s:'empty'},{d:30,s:'empty'},{d:31,s:'empty'},null,null,null,null],
  ] as ({d:number;s:DayState} | null)[][],
  today: {
    label: 'Vandaag — 10 mei',
    items: [
      '1 eetlepel brandnetel door ruwvoer',
      '1 eetlepel lijnzaad door ruwvoer',
      'Krachtvoer met granen weglaten',
      'Mest observeren en foto maken',
    ],
  },
};

export const SCAN_HISTORY = [
  { id: 'a', t: 'Pavo SpeediBeet',     score: 88, when: '2 mei',  rating: 'Goed' },
  { id: 'b', t: 'Equilin Magnesium',   score: 74, when: '28 apr', rating: 'Goed' },
  { id: 'c', t: 'Pavo Care 4 Life',    score: 62, when: '24 apr', rating: 'Matig' },
  { id: 'd', t: 'Cavalor Free Breath', score: 41, when: '14 apr', rating: 'Slecht' },
];

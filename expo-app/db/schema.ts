// TinyBase tables + values schema for EquiNova.
// Each table id matches a model in datamodel.md.
//
// TinyBase only supports flat cells (string | number | boolean), so:
//   - dates are stored as ISO strings (or 'YYYY-MM-DD' for date-only)
//   - cents are stored as integers
//   - arrays/objects (e.g. plan benefits) become child rows in their own table
//   - polymorphic FKs use {refType, refId} pairs
//
// Foreign keys are plain string cells. Relationships are wired in `relationships.ts`.

import type { TablesSchema, ValuesSchema } from 'tinybase';

export const TABLES_SCHEMA = {
  // ------------------------------------------------------------------ identity
  users: {
    email: { type: 'string' },
    name: { type: 'string' },
    avatarInitial: { type: 'string' },
    avatarUrl: { type: 'string' },
    locale: { type: 'string', default: 'nl-NL' },
    unitsSystem: { type: 'string', default: 'metric' }, // metric | imperial
    notificationsOn: { type: 'boolean', default: true },
    onboardedAt: { type: 'string' }, // ISO timestamp
    createdAt: { type: 'string' },
  },
  therapists: {
    name: { type: 'string' },
    title: { type: 'string' },
    bio: { type: 'string' },
    avatarUrl: { type: 'string' },
    avatarInitial: { type: 'string' },
    avatarColor: { type: 'string' },
    verified: { type: 'boolean', default: false },
  },

  // -------------------------------------------------------------- horse domain
  horses: {
    ownerId: { type: 'string' },
    name: { type: 'string' },
    breed: { type: 'string' },
    age: { type: 'number' },
    sex: { type: 'string' }, // merrie | ruin | hengst
    weightKg: { type: 'number' },
    stable: { type: 'string' },
    photoUrl: { type: 'string' },
    status: { type: 'string', default: 'active' }, // active | archived
    archivedAt: { type: 'string' },
    archivedNote: { type: 'string' },
    createdAt: { type: 'string' },
  },
  focusTopics: {
    icon: { type: 'string' },
    title: { type: 'string' },
    description: { type: 'string' },
    order: { type: 'number', default: 0 },
  },
  horseFocus: {
    horseId: { type: 'string' },
    focusTopicId: { type: 'string' },
    addedAt: { type: 'string' },
    extraLabel: { type: 'string' }, // free-text fallback (e.g. "Spijsvertering")
  },
  horseShares: {
    horseId: { type: 'string' },
    granteeUserId: { type: 'string' },
    therapistId: { type: 'string' },
    role: { type: 'string' }, // full | read_only
    since: { type: 'string' },
  },
  horseStats: {
    horseId: { type: 'string' },
    measuredAt: { type: 'string' },
    weightKg: { type: 'number' },
    energy: { type: 'number' },
    stoolScore: { type: 'string' },
    label: { type: 'string' }, // "Gewicht", "Energie", "Mest-score"
    valueLabel: { type: 'string' }, // display string e.g. "540 kg", "7 / 10", "B+"
    trend: { type: 'string' }, // "stabiel", "↑ +1 deze week"
    order: { type: 'number', default: 0 },
  },

  // -------------------------------------------------------------------- protocol
  protocols: {
    horseId: { type: 'string' },
    therapistId: { type: 'string' },
    title: { type: 'string' },
    subtitleAnalyse: { type: 'string' },
    subtitleProtocol: { type: 'string' },
    subtitleCalendar: { type: 'string' },
    totalWeeks: { type: 'number' },
    currentWeek: { type: 'number' },
    startedAt: { type: 'string' },
    status: { type: 'string', default: 'active' },
  },
  protocolPhases: {
    protocolId: { type: 'string' },
    order: { type: 'number' },
    title: { type: 'string' },
    state: { type: 'string' }, // done | active | upcoming
    weekStart: { type: 'number' },
    weekEnd: { type: 'number' },
    chipLabel: { type: 'string' },
  },
  protocolPhaseItems: {
    // simple bullet items shown inside a phase card
    phaseId: { type: 'string' },
    order: { type: 'number' },
    label: { type: 'string' },
  },
  protocolAnalyses: {
    protocolId: { type: 'string' },
    cause: { type: 'string' },
  },
  protocolAdvice: {
    analysisId: { type: 'string' },
    iconKey: { type: 'string' }, // leaf | run | horse
    title: { type: 'string' },
    body: { type: 'string' },
    order: { type: 'number' },
  },
  protocolTasks: {
    protocolId: { type: 'string' },
    phaseId: { type: 'string' },
    label: { type: 'string' },
    meta: { type: 'string' },
    kind: { type: 'string' }, // feeding | observation | care | other
    order: { type: 'number' },
    activeFrom: { type: 'string' },
    activeUntil: { type: 'string' },
    referenceItemId: { type: 'string' },
  },
  protocolTaskCompletions: {
    taskId: { type: 'string' },
    horseId: { type: 'string' },
    date: { type: 'string' }, // YYYY-MM-DD
    done: { type: 'boolean' },
    doneAt: { type: 'string' },
  },

  // ----------------------------------------------------------- observations
  observations: {
    horseId: { type: 'string' },
    authorId: { type: 'string' },
    date: { type: 'string' },
    note: { type: 'string' },
    mood: { type: 'number' }, // 1..5
    stoolScore: { type: 'string' },
    protocolTaskId: { type: 'string' },
    createdAt: { type: 'string' },
  },
  observationPhotos: {
    observationId: { type: 'string' },
    url: { type: 'string' },
    caption: { type: 'string' },
    kind: { type: 'string' },
  },
  timelineEvents: {
    horseId: { type: 'string' },
    occurredAt: { type: 'string' },
    whenLabel: { type: 'string' },
    kind: { type: 'string' },
    message: { type: 'string' },
    refType: { type: 'string' },
    refId: { type: 'string' },
    isNow: { type: 'boolean', default: false },
    order: { type: 'number', default: 0 },
  },

  // ----------------------------------------------------------------- scanner
  products: {
    brand: { type: 'string' },
    name: { type: 'string' },
    barcode: { type: 'string' },
    category: { type: 'string' },
  },
  ingredients: {
    name: { type: 'string' },
    description: { type: 'string' },
    defaultTag: { type: 'string' }, // good | warn | danger
  },
  scanResults: {
    userId: { type: 'string' },
    horseId: { type: 'string' },
    productId: { type: 'string' },
    productName: { type: 'string' },
    brand: { type: 'string' },
    scannedAt: { type: 'string' },
    whenLabel: { type: 'string' },
    score: { type: 'number' },
    rating: { type: 'string' }, // Goed | Matig | Slecht
    advice: { type: 'string' },
    photoUrl: { type: 'string' },
    bookmarked: { type: 'boolean', default: false },
  },
  scanIngredients: {
    scanId: { type: 'string' },
    ingredientId: { type: 'string' },
    name: { type: 'string' },
    tag: { type: 'string' },
    description: { type: 'string' },
    order: { type: 'number' },
  },

  // ----------------------------------------------------------------- library
  libraryItems: {
    slug: { type: 'string' },
    kind: { type: 'string' }, // Kruid | Voeding | Diagnose | Cursus | Locatie | Symptoom
    format: { type: 'string' }, // article | video | course | program
    title: { type: 'string' },
    description: { type: 'string' },
    body: { type: 'string' },
    videoUrl: { type: 'string' },
    heroImageUrl: { type: 'string' },
    durationLabel: { type: 'string' },
    durationSec: { type: 'number' },
    authorTherapistId: { type: 'string' },
    viewsLabel: { type: 'string' },
    publishedAt: { type: 'string' },
    isPlus: { type: 'boolean', default: false },
    isFeatured: { type: 'boolean', default: false },
    order: { type: 'number', default: 0 },
  },
  libraryChapters: {
    itemId: { type: 'string' },
    order: { type: 'number' },
    title: { type: 'string' },
    startLabel: { type: 'string' }, // "0:00"
    startSec: { type: 'number' },
  },
  libraryArticleSections: {
    itemId: { type: 'string' },
    order: { type: 'number' },
    heading: { type: 'string' },
    body: { type: 'string' },
  },
  libraryCategories: {
    label: { type: 'string' },
    order: { type: 'number' },
    isDefault: { type: 'boolean', default: false },
  },
  libraryItemCategories: {
    itemId: { type: 'string' },
    categoryId: { type: 'string' },
  },
  libraryItemFocus: {
    itemId: { type: 'string' },
    focusTopicId: { type: 'string' },
  },
  libraryBookmarks: {
    userId: { type: 'string' },
    itemId: { type: 'string' },
    createdAt: { type: 'string' },
  },
  libraryProgress: {
    userId: { type: 'string' },
    itemId: { type: 'string' },
    positionSec: { type: 'number' },
    progress: { type: 'number' }, // 0..1
    completed: { type: 'boolean', default: false },
    lastViewedAt: { type: 'string' },
  },
  seasonalTips: {
    month: { type: 'string' },
    monthOrder: { type: 'number' }, // 1..12
    body: { type: 'string' },
    ctaItemId: { type: 'string' },
    active: { type: 'boolean', default: false },
  },

  // --------------------------------------------------------------- community
  communityCategories: {
    label: { type: 'string' },
    order: { type: 'number' },
    isDefault: { type: 'boolean', default: false },
  },
  communityPosts: {
    authorUserId: { type: 'string' },
    authorName: { type: 'string' },
    authorInitial: { type: 'string' },
    authorAvatarColor: { type: 'string' },
    body: { type: 'string' },
    createdAt: { type: 'string' },
    whenLabel: { type: 'string' },
    likesCount: { type: 'number', default: 0 },
    repliesCount: { type: 'number', default: 0 },
    hasExpertReply: { type: 'boolean', default: false },
    categoryId: { type: 'string' },
    order: { type: 'number', default: 0 },
  },
  communityTags: {
    label: { type: 'string' },
  },
  communityPostTags: {
    postId: { type: 'string' },
    tagId: { type: 'string' },
    order: { type: 'number', default: 0 },
  },
  communityReplies: {
    postId: { type: 'string' },
    authorUserId: { type: 'string' },
    authorTherapistId: { type: 'string' },
    authorName: { type: 'string' },
    authorInitial: { type: 'string' },
    authorAvatarColor: { type: 'string' },
    authorIsExpert: { type: 'boolean', default: false },
    body: { type: 'string' },
    createdAt: { type: 'string' },
    whenLabel: { type: 'string' },
    likesCount: { type: 'number', default: 0 },
    repliesCount: { type: 'number', default: 0 },
    order: { type: 'number', default: 0 },
  },
  communityReactions: {
    userId: { type: 'string' },
    targetType: { type: 'string' }, // post | reply
    targetId: { type: 'string' },
    kind: { type: 'string', default: 'like' },
    createdAt: { type: 'string' },
  },

  // ----------------------------------------------------------- subscription
  plans: {
    label: { type: 'string' },
    name: { type: 'string' },
    priceCents: { type: 'number' },
    currency: { type: 'string', default: 'EUR' },
    interval: { type: 'string' }, // monthly | one_time
    priceSuffix: { type: 'string' }, // "/ maand", "eenmalig"
    description: { type: 'string' },
    isRecommended: { type: 'boolean', default: false },
    order: { type: 'number', default: 0 },
  },
  planBenefits: {
    planId: { type: 'string' },
    label: { type: 'string' },
    order: { type: 'number' },
  },
  subscriptions: {
    userId: { type: 'string' },
    planId: { type: 'string' },
    status: { type: 'string' }, // active | cancelled | past_due
    priceCents: { type: 'number' },
    currency: { type: 'string', default: 'EUR' },
    interval: { type: 'string' },
    startedAt: { type: 'string' },
    startedLabel: { type: 'string' }, // "Sinds april 2026"
    renewsAt: { type: 'string' },
    renewsLabel: { type: 'string' }, // "Verlengt 22 mei"
    cancelledAt: { type: 'string' },
    maxHorses: { type: 'number' },
  },
  payments: {
    subscriptionId: { type: 'string' },
    date: { type: 'string' },
    dateLabel: { type: 'string' },
    amountLabel: { type: 'string' },
    amountCents: { type: 'number' },
    currency: { type: 'string', default: 'EUR' },
    status: { type: 'string', default: 'paid' },
    order: { type: 'number' },
  },

  // --------------------------------------------------- notifications / settings
  notificationPreferences: {
    userId: { type: 'string' },
    reminderProtocol: { type: 'boolean', default: true },
    reminderCommunity: { type: 'boolean', default: true },
    reminderSeasonalTips: { type: 'boolean', default: true },
    activeRemindersCount: { type: 'number', default: 0 },
    pushToken: { type: 'string' },
  },
  accountSettings: {
    // "Algemeen" rows on account/index.tsx — UI-only catalog
    userId: { type: 'string' },
    iconKey: { type: 'string' },
    title: { type: 'string' },
    subtitle: { type: 'string' },
    route: { type: 'string' },
    order: { type: 'number' },
  },
  dataExports: {
    userId: { type: 'string' },
    horseId: { type: 'string' },
    format: { type: 'string' },
    requestedAt: { type: 'string' },
    completedAt: { type: 'string' },
    fileUrl: { type: 'string' },
  },

  // ------------------------------------------------------------- Nova AI chat
  chatSessions: {
    userId: { type: 'string' },
    horseId: { type: 'string' },
    startedAt: { type: 'string' },
  },
  chatMessages: {
    sessionId: { type: 'string' },
    role: { type: 'string' }, // assistant | user
    body: { type: 'string' },
    createdAt: { type: 'string' },
    order: { type: 'number' },
  },
  novaFallbackReplies: {
    body: { type: 'string' },
    order: { type: 'number' },
  },

  // -------------------------------------------------- static screen copy
  screenCopy: {
    screen: { type: 'string' }, // identifier, e.g. 'welcome'
    field: { type: 'string' }, // e.g. 'tagline', 'title', 'body'
    value: { type: 'string' },
  },

  // ------------------------------------------------------------ intake bookings
  intakeBookings: {
    userId: { type: 'string' },
    horseId: { type: 'string' },
    therapistId: { type: 'string' },
    scheduledAt: { type: 'string' },
    slotLabel: { type: 'string' }, // "Vrijdag 18 mei · 14:30"
    durationMinutes: { type: 'number', default: 30 },
    status: { type: 'string', default: 'pending' },
    notes: { type: 'string' },
  },
} as const satisfies TablesSchema;

export const VALUES_SCHEMA = {
  // app-wide state
  schemaVersion: { type: 'number', default: 1 },
  seeded: { type: 'boolean', default: false },
  onboarded: { type: 'boolean', default: false },
  currentUserId: { type: 'string', default: '' },
  currentHorseId: { type: 'string', default: '' },
  currentMonthLabel: { type: 'string', default: 'Mei 2026' },
  currentMonthDay: { type: 'number', default: 10 },
  todayLabel: { type: 'string', default: 'Vandaag — 10 mei' },
  detailTodayLabel: { type: 'string', default: 'Vandaag · 16 mei' },
  scannerHintText: {
    type: 'string',
    default: 'Richt de camera op een verpakking, ingrediëntenlijst of voederzak.',
  },
  novaIntroText: { type: 'string', default: 'Hi Marit! Wat speelt er bij Nova?' },
  librarySearchPlaceholder: { type: 'string', default: "Zoek in 240+ artikelen, video's, kruiden" },
  novaSubtitle: { type: 'string', default: "AI-assistent · getraind op Shelley's werk" },
} as const satisfies ValuesSchema;

export type TableId = keyof typeof TABLES_SCHEMA;

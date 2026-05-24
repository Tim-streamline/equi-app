// Static, non-synced UI strings consumed via useValue(). These were
// TinyBase Values in the previous implementation; with PowerSync's
// useQuery driving everything else, the few app-level constants live
// here as a plain map.

export const APP_STRINGS: Record<string, string | number | boolean> = {
  schemaVersion: 1,
  currentMonthLabel: 'Mei 2026',
  currentMonthDay: 10,
  todayLabel: 'Vandaag — 10 mei',
  detailTodayLabel: 'Vandaag · 16 mei',
  scannerHintText: 'Richt de camera op een verpakking, ingrediëntenlijst of voederzak.',
  novaIntroText: 'Hi Marit! Wat speelt er bij Nova?',
  librarySearchPlaceholder: "Zoek in 240+ artikelen, video's, kruiden",
  novaSubtitle: "AI-assistent · getraind op Shelley's werk",
};

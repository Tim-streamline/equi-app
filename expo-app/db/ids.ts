// Well-known row IDs used by the seed + screens.
// Deterministic so that hooks can target "the current user / horse / protocol".

export const IDS = {
  user: 'user-marit',
  therapist: 'therapist-shelley',
  coCarer: 'user-lisanne',
  horse: 'horse-nova',
  archivedHorse: 'horse-pip',
  protocol: 'protocol-nova-jeuk',
  protocolAnalysis: 'analysis-nova-jeuk',
  subscription: 'sub-marit-plus',
  notificationPrefs: 'np-marit',
  chatSession: 'chat-nova-default',
  intakeBooking: 'intake-nova-may18',
  seasonalMay: 'seasonal-may-2026',
} as const;

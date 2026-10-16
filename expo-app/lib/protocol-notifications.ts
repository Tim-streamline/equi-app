export type PhaseNotification = {
  type: 'protocol_phase_preview';
  userId: string;
  horseId: string;
  protocolId: string;
  phaseIds: string[];
};

export function phaseNotificationRoute(data: unknown, userId: string, notificationId: string) {
  if (!data || typeof data !== 'object') return null;
  const value = data as Partial<PhaseNotification>;
  const identifier = (id: unknown): id is string => typeof id === 'string' && /^[a-zA-Z0-9-]+$/.test(id);
  if (value.type !== 'protocol_phase_preview' || value.userId !== userId || !identifier(value.horseId) || !identifier(value.protocolId)
    || !Array.isArray(value.phaseIds) || !value.phaseIds.length || !value.phaseIds.every(identifier)) return null;
  return {
    pathname: '/(tabs)/(pager)/protocol' as const,
    params: { tab: 'kalender', horseId: value.horseId, protocolId: value.protocolId, phaseIds: value.phaseIds.join(','), t: notificationId },
  };
}

/** A cached locked phase must leave the link pending for the fresh dashboard. */
export function accessibleNotificationPhase<T extends { id: string; accessible: boolean }>(
  protocol: { id: string; phases: T[] } | null | undefined,
  protocolId: string | undefined,
  phaseIds: string | undefined,
): T | null {
  if (!protocol || protocol.id !== protocolId || !phaseIds) return null;
  return protocol.phases.find((phase) => phase.accessible && phaseIds.split(',').includes(phase.id)) ?? null;
}

/** Credit reminders only navigate within the account they were issued for. */
export function creditNotificationRoute(data: unknown, userId: string) {
  if (!data || typeof data !== 'object') return null;
  const value = data as { type?: unknown; userId?: unknown };
  return value.type === 'credit_expiry' && value.userId === userId
    ? { pathname: '/(tabs)/account/credits' as const } : null;
}

export function intakeNotificationRoute(data: unknown, userId: string) {
  if (!data || typeof data !== 'object') return null;
  const value = data as { type?: unknown; userId?: unknown; bookingId?: unknown };
  return value.type === 'intake_reminder' && value.userId === userId && typeof value.bookingId === 'string' && /^[a-f0-9-]{36}$/i.test(value.bookingId)
    ? { pathname: '/intake/reminder' as const, params: { bookingId: value.bookingId, userId } } : null;
}

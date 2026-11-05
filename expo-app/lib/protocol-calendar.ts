import type { DashboardOrderItem, DashboardPhase, DashboardProtocol } from './horse-dashboard';

export const calendarDate = (value: string | null | undefined) => value?.slice(0, 10) ?? '';
export function shortDate(date: string, options: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' }) {
  return new Date(date + 'T12:00:00').toLocaleDateString('nl-NL', options);
}
export function phaseStarts(protocol: DashboardProtocol, date: string) {
  return protocol.preparing ? [] : protocol.phases.filter(phase => calendarDate(phase.startsAt) === date);
}
export function nextPhaseStart(protocol: DashboardProtocol, today: string) {
  if (protocol.preparing) return [];
  const future = protocol.phases.filter(phase => calendarDate(phase.startsAt) > today).sort((a,b) => calendarDate(a.startsAt).localeCompare(calendarDate(b.startsAt)));
  const limit = new Date(today + 'T00:00:00Z'); limit.setUTCDate(limit.getUTCDate() + 14);
  return future.length && calendarDate(future[0].startsAt) <= limit.toISOString().slice(0,10) ? future.filter(phase => calendarDate(phase.startsAt) === calendarDate(future[0].startsAt)) : [];
}
export function phaseOrderItems(items: DashboardOrderItem[], phaseIds: string[]): DashboardOrderItem[] {
  return items.flatMap(item => {
    const components = item.components?.filter(part => phaseIds.includes(part.phaseId)) ?? [];
    if (!components.length) return [];
    const complete = components.every(part => part.quantity != null);
    const quantity = complete ? components.reduce((sum, part) => sum + (part.quantity ?? 0), 0) : null;
    let amount = quantity, unit = item.unit;
    if (amount != null && amount >= 1000 && (unit === 'g' || unit === 'ml')) { amount /= 1000; unit = unit === 'g' ? 'kg' : 'l'; }
    return [{ ...item, components, selectionIds: components.map(part => part.selectionId), quantity, calculationComplete: complete,
      quantityLabel: amount == null || !unit ? null : amount.toLocaleString('nl-NL', { maximumFractionDigits: 9 }) + ' ' + unit }];
  });
}
export function dayProgress(cell: { total?: number; done?: number; state: string }) {
  return cell.total != null ? (cell.total > 0 ? Math.max(0, Math.min(1, (cell.done ?? 0) / cell.total)) : null)
    : cell.state === 'complete' ? 1 : cell.state === 'partial' ? .5 : cell.state === 'missed' ? 0 : null;
}
export function phaseSubtitle(phase: DashboardPhase) {
  return [phase.startsAt ? 'Start ' + shortDate(calendarDate(phase.startsAt)) : null, phase.weekLabel, phase.durationLabel].filter(Boolean).join(' · ');
}

/** Fixed lane per phase; ranges are clipped to the actual dates in this month row. */
export function weekPhaseLines(cells: ({date: string} | null)[], phases: DashboardPhase[], preparing = false) {
  return phases.map((phase, lane) => {
    const start = calendarDate(phase.startsAt), end = calendarDate(phase.endsAt);
    const columns = preparing || !start || !end ? [] : cells.flatMap((cell, index) => cell && cell.date >= start && cell.date < end ? [index] : []);
    return { phaseId: phase.id, lane, start: columns[0] ?? 0, length: columns.length };
  });
}

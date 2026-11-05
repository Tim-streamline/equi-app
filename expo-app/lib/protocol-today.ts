import type { DashboardSupplement } from './horse-dashboard';

export const administrationKey = (item: DashboardSupplement) => `${item.id}:${item.administration ?? 1}`;

export function administrationLabel(item: DashboardSupplement): string {
  const number = item.administration ?? 1;
  const count = item.administrationsPerDay ?? 1;
  if (number === 1) return 'Ochtend';
  if ((count === 2 && number === 2) || (count === 3 && number === 3)) return 'Avond';
  if (count === 3 && number === 2) return 'Middag';
  return `Gift ${number}`;
}

export function todayGroups(items: DashboardSupplement[], records: {
  protocolPhaseSupplementId: string; administration?: number | null; done?: boolean | number;
}[]) {
  const local = new Map(records.map(row => [`${row.protocolPhaseSupplementId}:${row.administration ?? 1}`, row]));
  const groups = new Map<string, DashboardSupplement[]>();
  for (const item of items) {
    const record = local.get(administrationKey(item));
    const label = administrationLabel(item);
    const rows = groups.get(label) ?? [];
    rows.push({ ...item, done: record ? !!record.done : !!item.done });
    groups.set(label, rows);
  }
  const rank = (label: string) => ['Ochtend', 'Middag', 'Avond'].includes(label) ? ['Ochtend', 'Middag', 'Avond'].indexOf(label) : 3;
  return [...groups].sort(([a], [b]) => rank(a) - rank(b)).map(([label, rows]) => ({ label, items: rows, done: rows.filter(row => row.done).length }));
}

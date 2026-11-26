import type { DashboardSupplement } from './horse-dashboard';

export const PHASE_COLORS = ['#18BAB0', '#0D5C5B', '#5FD7CB', '#B58339'];

export const administrationKey = (item: DashboardSupplement) => `${item.id}:${item.administration ?? 1}`;

/**
 * Gifts are named by meaning, not time of day: every product is given once a day, at any moment,
 * and a product set to more gifts per day ("Giften per dag" in the admin) is preferably given again.
 */
export function administrationLabel(item: DashboardSupplement): string {
  const number = item.administration ?? 1;
  if (item.recordedOnly) return `Gift ${number}`;
  return number === 1 ? 'Elke dag' : number === 2 ? 'Tweede gift' : number === 3 ? 'Derde gift' : `Gift ${number}`;
}

/** Only products given more than once a day show their moment; once-daily rows stay compact. */
export function inlineTiming(item: DashboardSupplement): string | null {
  return (item.administrationsPerDay ?? 1) > 1 ? administrationLabel(item) : null;
}

export function todayGroups(items: DashboardSupplement[], records: {
  protocolPhaseSupplementId: string; administration?: number | null; done?: boolean | number; dosage?: string | null;
}[]) {
  const local = new Map(records.map(row => [`${row.protocolPhaseSupplementId}:${row.administration ?? 1}`, row]));
  const dailyDosages = new Map<string, string | null>();
  for (const record of records) {
    if (record.dosage !== undefined && !dailyDosages.has(record.protocolPhaseSupplementId)) {
      dailyDosages.set(record.protocolPhaseSupplementId, record.dosage);
    }
  }
  const groups = new Map<string, DashboardSupplement[]>();
  const history: DashboardSupplement[] = [];
  for (const item of items) {
    if (item.recordedOnly) {
      history.push(item);
      continue;
    }
    const record = local.get(administrationKey(item));
    const label = administrationLabel(item);
    const rows = groups.get(label) ?? [];
    rows.push({ ...item, dosage: record?.dosage !== undefined ? record.dosage : dailyDosages.has(item.id) ? dailyDosages.get(item.id)! : item.dosage, done: record ? !!record.done : !!item.done });
    groups.set(label, rows);
  }
  const scheduled = [...groups].map(([label, rows]) => ({ label, number: rows[0]?.administration ?? 1, items: rows, done: rows.filter(row => row.done).length }))
    .sort((a, b) => a.number - b.number);
  return history.length ? [...scheduled, { label: 'Eerder geregistreerd', number: null, items: history, done: null }] : scheduled;
}

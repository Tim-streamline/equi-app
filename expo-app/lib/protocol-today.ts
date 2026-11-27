import type { DashboardExtension, DashboardSupplement } from './horse-dashboard';

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

/**
 * The sentence above Today's list while the last week of a phase is extended, e.g. "Brandnetel is in deze fase
 * minder vaak gegeven. Geef het nog 5 dagen, daarna start week 7." Today's ticks count down right away.
 */
export function extensionText(extension: DashboardExtension | null | undefined, today: DashboardSupplement[]): string | null {
  if (!extension?.items.length) return null;
  const ticked = new Set(today.filter(item => item.done && !item.recordedOnly).map(item => item.id));
  const open = extension.items
    .map(item => ({ name: item.name, left: item.remaining - (ticked.has(item.id) ? 1 : 0) }))
    .filter(item => item.left > 0);
  // Only when every running phase is catching up does a next week (or the end of the plan) wait for them.
  const waiting = extension.extended !== false;
  const then = extension.nextWeek ? `start week ${extension.nextWeek}` : 'is je herstelplan rond';
  if (!open.length) return !waiting ? null : extension.nextWeek ? `Morgen start week ${extension.nextWeek}.` : 'Morgen is je herstelplan rond.';
  return open.map((item, index) => `${item.name} is in deze fase minder vaak gegeven. Geef het nog ${item.left} ${item.left === 1 ? 'dag' : 'dagen'}`
    + (waiting && index === open.length - 1 ? `, daarna ${then}.` : '.')).join(' ');
}

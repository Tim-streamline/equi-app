import type { RepeaterRow, RepeaterSub } from './schema';

const PLAIN_NUMBER = /^\d+(?:[.,]\d*)?$/;
const text = (value: unknown) => typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '';

/** Radio columns that only hold the unit of another column; they are shown together with that number. */
export const repeaterUnitColumns = (subs: readonly RepeaterSub[]) => new Set(subs.map(sub => sub.unitField).filter((id): id is string => !!id));

/** A plain number was entered, but its per-row unit (e.g. gram or kilo) is still open. */
export function repeaterUnitMissing(row: RepeaterRow | null | undefined, sub: RepeaterSub): boolean {
  return !!row && sub.type === 'number' && !!sub.unitField && PLAIN_NUMBER.test(text(row[sub.id])) && !text(row[sub.unitField]);
}

/** Read-only text for one value, with its fixed or chosen unit. Legacy free text is shown as saved. */
export function repeaterValueText(row: RepeaterRow, sub: RepeaterSub): string {
  const raw = text(row[sub.id]);
  const unit = sub.unitField ? text(row[sub.unitField]) : sub.unit ?? '';
  return unit && PLAIN_NUMBER.test(raw) ? `${raw} ${unit}` : raw;
}

/** Allow a decimal comma or dot while typing, without turning pasted text into a quantity. */
export const isRepeaterNumberInput = (value: string) => /^\d*(?:[.,]\d*)?$/.test(value);

/** Display legacy kg values without rewriting the saved answer or guessing other units. */
export function repeaterNumberText(value: unknown, unit?: string): string {
  const raw = typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '';
  if (/^\d+(?:[.,]\d*)?$/.test(raw)) return raw;
  if (!unit) return '';
  const escaped = unit.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`^(\\d+(?:[.,]\\d+)?)\\s*${escaped}$`, 'i').exec(raw)?.[1] ?? '';
}

// Legacy rows receive an identity without rewriting answers merely by viewing them.
// Their next edit persists the identity alongside the unchanged answer properties.
const legacyIds = new WeakMap<RepeaterRow, string>();
const newId = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
  ? crypto.randomUUID()
  : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 3) | 8).toString(16);
  });

export function createRepeaterRow(): RepeaterRow {
  return { _rowId: newId() };
}

export function withRepeaterRowIds(rows: RepeaterRow[]): RepeaterRow[] {
  const used = new Set<string>();
  return rows.map(row => {
    let id = typeof row._rowId === 'string' && row._rowId ? row._rowId : legacyIds.get(row);
    if (!id || used.has(id)) {
      id = legacyIds.get(row);
      if (!id || used.has(id)) id = newId();
      legacyIds.set(row, id);
    }
    used.add(id);
    return row._rowId === id ? row : { ...row, _rowId: id };
  });
}

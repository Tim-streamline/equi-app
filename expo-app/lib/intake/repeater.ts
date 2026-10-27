import type { RepeaterRow } from './schema';

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

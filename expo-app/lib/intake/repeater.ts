import type { RepeaterRow } from './schema';

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

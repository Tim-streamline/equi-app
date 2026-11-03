type Operation = { op: string; table: string; id: string; opData?: Record<string, unknown> | null; previousValues?: Record<string, unknown> };
type Database = { getOptional<T>(sql: string, params: string[]): Promise<T | null> };
const answerIdentity = ['response_id', 'section_id', 'field_id'] as const;
const libraryViewIdentity = ['user_id', 'item_id'] as const;

/** Keep the queued value, adding only the identity needed to recover a cleaned-up row. */
export async function prepareSyncOperations(database: Database, operations: readonly Operation[]) {
  return Promise.all(operations.map(async entry => {
    let data = entry.opData ?? null;
    const identityColumns = entry.table === 'intake_answers' ? answerIdentity
      : entry.table === 'library_progress' ? libraryViewIdentity : null;
    if (identityColumns && entry.op === 'PATCH') {
      const identity: Record<string, unknown> = {};
      for (const key of identityColumns) identity[key] = data?.[key] ?? entry.previousValues?.[key];
      if (identityColumns.some(key => !identity[key])) {
        // Compatibility for edits queued before identity tracking was enabled.
        const local = await database.getOptional<Record<string, unknown>>(
          // Table and column names come only from the allowlist above.
          `SELECT ${identityColumns.join(', ')} FROM ${entry.table} WHERE id = ?`, [entry.id]);
        for (const key of identityColumns) identity[key] ??= local?.[key];
      }
      data = { ...identity, ...data };
    }
    return { op: entry.op, id: entry.id, type: entry.table, data };
  }));
}

/** Booking and intake share one UUID; answer rows always reference that UUID. */
type Database = {
  getOptional<T>(sql: string, params?: any[]): Promise<T | null>;
  execute(sql: string, params?: any[]): Promise<any>;
  writeTransaction<T>(callback: (tx: any) => Promise<T>): Promise<T>;
};
// Choose the selected horse's unfinished protocol; completed history never steals its intake.
export const bookingQuery = `SELECT i.id, i.submitted_at, i.completed_at, i.updated_at FROM intake_bookings i
 JOIN protocols p ON p.id = i.protocol_id
 WHERE i.user_id = ? AND i.horse_id = ? AND i.status != 'cancelled'
 AND p.status NOT IN ('completed','archived')
 ORDER BY i.submitted_at IS NOT NULL, i.created_at DESC, i.id DESC LIMIT 1`;
export async function openOrCreateIntake(db: Database, userId: string, horseId: string, create: () => Promise<string>): Promise<string> {
  if (!userId || !horseId) throw new Error('Selecteer eerst je paard om de intake in te vullen.');
  const existing = await db.getOptional<{ id: string }>(bookingQuery, [userId, horseId]);
  if (existing) return existing.id;
  // Recover a purchased intake that has not synced yet. Never create an unpaid intake.
  const id = await create();
  return ensureIntake(db, userId, horseId, () => id);
}
export async function ensureIntake(db: Database, userId: string, horseId: string, authorizedId: () => string): Promise<string> {
  if (!userId || !horseId) throw new Error('Selecteer eerst je paard om de intake in te vullen.');
  const id = authorizedId();
  return db.writeTransaction(async tx => {
    const row = await tx.getOptional('SELECT id,user_id,horse_id FROM intake_bookings WHERE id = ?', [id]);
    if (row) {
      if (row.user_id !== userId || row.horse_id !== horseId) throw new Error('Deze intake hoort bij een ander paard of account.');
      return row.id;
    }
    throw new Error('Je intake wordt nog gesynchroniseerd. Probeer het zo opnieuw.');
  });
}
export async function saveAnswer(db: Database, bookingId: string, section: string, field: string, value: unknown, newId: () => string) {
  await db.writeTransaction(async tx => {
    if (value == null || value === '' || (Array.isArray(value) && value.length === 0)) {
      await tx.execute('DELETE FROM intake_answers WHERE response_id = ? AND section_id = ? AND field_id = ?', [bookingId, section, field]);
      return;
    }
    const existing = await tx.getOptional('SELECT id FROM intake_answers WHERE response_id = ? AND section_id = ? AND field_id = ? LIMIT 1', [bookingId, section, field]);
    const now = new Date().toISOString();
    if (existing) await tx.execute('UPDATE intake_answers SET value = ?, updated_at = ? WHERE id = ?', [JSON.stringify(value), now, existing.id]);
    else await tx.execute('INSERT INTO intake_answers (id,response_id,section_id,field_id,value,created_at,updated_at) VALUES (?,?,?,?,?,?,?)', [newId(),bookingId,section,field,JSON.stringify(value),now,now]);
  });
}

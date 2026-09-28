/** Booking and intake share one UUID; answer rows always reference that UUID. */
type Database = {
  getOptional<T>(sql: string, params?: any[]): Promise<T | null>;
  execute(sql: string, params?: any[]): Promise<any>;
  writeTransaction<T>(callback: (tx: any) => Promise<T>): Promise<T>;
};
export const bookingQuery = `SELECT id, submitted_at, updated_at FROM intake_bookings WHERE user_id = ? AND horse_id = ? AND status != 'cancelled' ORDER BY created_at DESC, id DESC LIMIT 1`;
export async function ensureIntake(db: Database, userId: string, horseId: string, newId: () => string): Promise<string> {
  if (!userId || !horseId) throw new Error('Selecteer eerst je paard om de intake in te vullen.');
  return db.writeTransaction(async tx => {
    const row = await tx.getOptional(bookingQuery, [userId, horseId]);
    if (row) return row.id;
    const id = newId();
    const now = new Date().toISOString();
    await tx.execute(`INSERT INTO intake_bookings (id,user_id,horse_id,status,intake_status,started_at,created_at,updated_at) VALUES (?,?,?,'pending','draft',?,?,?)`, [id,userId,horseId,now,now,now]);
    return id;
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

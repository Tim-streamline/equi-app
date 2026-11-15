import type { AttachmentStorage, LocalAttachment } from './types';

export type AttachmentOperation = { table: string; opData?: Record<string, unknown> | null };
export function attachmentIds(operations: readonly AttachmentOperation[]): string[] {
  const ids = new Set<string>();
  for (const op of operations) {
    if (op.table !== 'intake_answers' || typeof op.opData?.value !== 'string') continue;
    let value: unknown;
    try { value = JSON.parse(op.opData.value); } catch { continue; }
    if (!Array.isArray(value)) continue;
    for (const ref of value) {
      if (typeof ref !== 'string') continue;
      const match = /^attachment:([a-f0-9-]{36}):/i.exec(ref);
      if (match) ids.add(match[1]);
    }
  }
  return [...ids];
}

// Responses that say the server will not accept this file, however often it is sent:
// the question or intake is gone, the intake is closed, or the file itself is refused.
const REFUSED = new Set([404, 409, 413, 422]);
const GIVE_UP_AFTER = 3;

/**
 * Upload bytes before acknowledging answers or a later submission in the CRUD queue.
 * A file the server keeps refusing is given up on, so it cannot hold back every later
 * answer forever. Its answer then syncs without the file; the server does not accept a
 * submission that references a file it never received.
 */
export async function flushAttachmentUploads(operations: readonly AttachmentOperation[], userId: string,
  storage: AttachmentStorage, upload: (record: LocalAttachment) => Promise<void>, checkSession: () => void) {
  for (const id of attachmentIds(operations)) {
    checkSession();
    const record = await storage.get(userId, id);
    checkSession();
    if (!record || record.uploaded) continue; // Existing server attachments have no local record.
    if (record.userId !== userId) throw new Error('Deze foto hoort bij een ander account.');
    if (record.rejected) continue;
    try {
      await upload(record);
      checkSession();
      await storage.put({ ...record, uploaded: true, error: undefined, rejections: undefined, rejected: undefined });
    } catch (error) {
      checkSession();
      const status = error && typeof error === 'object' && 'status' in error ? error.status : undefined;
      const refused = typeof status === 'number' && REFUSED.has(status);
      const rejections = (record.rejections ?? 0) + (refused ? 1 : 0);
      const rejected = refused && rejections >= GIVE_UP_AFTER;
      await storage.put({ ...record, error: error instanceof Error ? error.message : 'Uploaden mislukt.', rejections, rejected });
      if (rejected) continue; // The queue moves on; the file itself has to be chosen again.
      throw error; // PowerSync retains this transaction and retries after reconnect.
    }
  }
  checkSession();
}

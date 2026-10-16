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

/** Upload bytes before acknowledging answers or a later submission in the CRUD queue. */
export async function flushAttachmentUploads(operations: readonly AttachmentOperation[], userId: string,
  storage: AttachmentStorage, upload: (record: LocalAttachment) => Promise<void>, checkSession: () => void) {
  for (const id of attachmentIds(operations)) {
    checkSession();
    const record = await storage.get(userId, id);
    checkSession();
    if (!record || record.uploaded) continue; // Existing server attachments have no local record.
    if (record.userId !== userId) throw new Error('Deze foto hoort bij een ander account.');
    try {
      await upload(record);
      checkSession();
      await storage.put({ ...record, uploaded: true, error: undefined });
    } catch (error) {
      checkSession();
      await storage.put({ ...record, error: error instanceof Error ? error.message : 'Uploaden mislukt.' });
      throw error; // PowerSync retains this transaction and retries after reconnect.
    }
  }
  checkSession();
}

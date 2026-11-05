// Serialize attachment cleanup behind already-started writes. Invalidate picker
// reads that began before a reset, including a blob fetch that resolves later.
const tails = new Map<string, Promise<unknown>>();
const generations = new Map<string, number>();
export function attachmentWriteCheck(userId: string) {
  const generation = generations.get(userId);
  return () => {
    if (generation !== generations.get(userId)) throw new Error('De lokale gegevens zijn opnieuw ingesteld.');
  };
}
export function writeAttachment<T>(userId: string, action: () => Promise<T>): Promise<T> {
  const check = attachmentWriteCheck(userId);
  return enqueue(userId, async () => { check(); return action(); });
}
export function resetAttachments(userId: string, action: () => Promise<void>) {
  generations.set(userId, (generations.get(userId) ?? 0) + 1);
  return enqueue(userId, action);
}
function enqueue<T>(userId: string, action: () => Promise<T>): Promise<T> {
  const result = (tails.get(userId) ?? Promise.resolve()).then(action);
  const tail = result.catch(() => {});
  tails.set(userId, tail);
  void tail.then(() => { if (tails.get(userId) === tail) tails.delete(userId); });
  return result;
}

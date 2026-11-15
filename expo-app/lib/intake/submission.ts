/** Finish queued answers (and their attachment uploads) before finalizing on the server. */
export async function waitForIntakeUploads(
  db: { getUploadQueueStats(): Promise<{ count: number }> },
  checkSession: () => void,
  timeoutMs = 30000,
) {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    checkSession();
    if ((await db.getUploadQueueStats()).count === 0) return;
    if (Date.now() >= deadline) throw new Error('Je antwoorden of foto’s worden nog gesynchroniseerd. Maak verbinding met internet en probeer opnieuw zodra de uploads klaar zijn.');
    await new Promise(resolve => setTimeout(resolve, 250));
  }
}

/**
 * A file the server refused no longer holds back the upload queue, so an empty queue
 * does not mean every file arrived. Stop before finalizing, with what to do about it.
 */
export async function assertIntakeFilesUploaded(
  answers: Record<string, Record<string, unknown> | undefined>, userId: string,
  storage: { get(userId: string, id: string): Promise<{ uploaded: boolean } | null> },
) {
  for (const section of Object.values(answers)) {
    for (const value of Object.values(section ?? {})) {
      for (const reference of Array.isArray(value) ? value : []) {
        const id = typeof reference === 'string' ? /^attachment:([a-f0-9-]{36}):/i.exec(reference)?.[1] : undefined;
        // No local record: the file was added on another device and is already on the server.
        if (id && (await storage.get(userId, id))?.uploaded === false) {
          throw new Error('Een of meer foto’s of bestanden zijn niet geüpload. Verwijder ze en kies ze opnieuw voordat je de intake verstuurt.');
        }
      }
    }
  }
}

export function formatIntakeDate(value: string | null) {
  if (!value) return '';
  const iso = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value) ? `${value.replace(' ', 'T')}Z` : value;
  return new Date(iso).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
}

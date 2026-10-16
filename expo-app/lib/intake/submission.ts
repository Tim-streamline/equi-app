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

export function formatIntakeDate(value: string | null) {
  if (!value) return '';
  const iso = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value) ? `${value.replace(' ', 'T')}Z` : value;
  return new Date(iso).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
}

export type SyncFailureKind = 'timeout' | 'cancelled' | 'http' | 'network' | 'storage' | 'stalled' | 'unknown';

export class SyncRequestError extends Error {
  kind: SyncFailureKind;
  status?: number;
  constructor(message: string, kind: SyncFailureKind, status?: number) {
    super(message);
    this.name = 'SyncRequestError';
    this.kind = kind;
    this.status = status;
  }
}

const requests = new Set<AbortController>();
export function cancelSyncRequests() {
  for (const controller of requests) controller.abort();
}

/** Bound the whole operation, including response bodies. Ignore late transport results. */
export async function syncRequest<T>(timeoutMs: number, work: (signal: AbortSignal, check: () => void) => Promise<T>, parent?: AbortSignal, cancellable = true): Promise<T> {
  const controller = new AbortController();
  let timedOut = false;
  const abort = () => controller.abort();
  const failure = () => new SyncRequestError(timedOut
    ? 'De verbinding duurt te lang. Controleer je internet en probeer opnieuw.'
    : 'De upload is onderbroken. Probeer opnieuw.', timedOut ? 'timeout' : 'cancelled');
  const check = () => { if (controller.signal.aborted) throw failure(); };
  if (cancellable) requests.add(controller);
  parent?.addEventListener('abort', abort);
  if (parent?.aborted) abort();
  const timer = setTimeout(() => { timedOut = true; abort(); }, timeoutMs);
  let rejectAbort: () => void = () => {};
  try {
    check();
    const cancelled = new Promise<never>((_, reject) => {
      rejectAbort = () => reject(failure());
      controller.signal.addEventListener('abort', rejectAbort);
    });
    return await Promise.race([work(controller.signal, check), cancelled]);
  } catch (error) {
    check();
    throw error;
  } finally {
    clearTimeout(timer);
    controller.signal.removeEventListener('abort', rejectAbort);
    parent?.removeEventListener('abort', abort);
    requests.delete(controller);
  }
}

export function syncHttpError(status: number): SyncRequestError {
  const message = status === 401 ? 'Je sessie kon niet worden vernieuwd. Probeer opnieuw.'
    : status === 403 ? 'Je hebt geen toegang tot deze intake. Neem contact met ons op.'
    : status === 404 ? 'Deze intake of fotovraag is niet beschikbaar. Vernieuw de app en probeer opnieuw.'
    : status === 409 ? 'Deze intake kan niet meer worden aangepast. Neem contact met ons op.'
    : status === 413 ? 'Het bestand is te groot. Kies een kleiner bestand.'
    : status === 422 ? 'Dit bestand of deze gegevens zijn niet geaccepteerd. Controleer ze en probeer opnieuw.'
    : status === 429 ? 'Te veel uploadpogingen. Wacht een minuut en probeer opnieuw.'
    : 'Uploaden is niet gelukt. Controleer je verbinding en probeer opnieuw.';
  return new SyncRequestError(message, 'http', status);
}

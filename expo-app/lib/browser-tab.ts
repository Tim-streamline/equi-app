const SESSION_KEY = 'equinova:tab';
const REGISTRY_KEY = 'equinova:tabs';
const REMEMBERED = 8;
// An older upload queue is more likely to overwrite newer answers than to recover lost ones.
const TAKE_OVER_WITHIN_MS = 30 * 24 * 60 * 60 * 1000;
type Entry = { id: string; at: number };

/** Shared identity for a browser tab's database, reset marker and attachments. */
export function browserTabId(): string {
  let id = sessionStorage.getItem(SESSION_KEY);
  if (!id) {
    id = crypto.randomUUID();
    sessionStorage.setItem(SESSION_KEY, id);
  }
  return id;
}

/** Identities this browser used, most recent first. Storage can be blocked or hold anything. */
function remembered(): Entry[] {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(REGISTRY_KEY) ?? '[]');
    if (!Array.isArray(saved)) return [];
    return saved.filter((entry): entry is Entry => typeof entry?.id === 'string' && !!entry.id && typeof entry.at === 'number')
      .sort((a, b) => b.at - a.at);
  } catch { return []; }
}
function remember(id: string) {
  try {
    const entries = [{ id, at: Date.now() }, ...remembered().filter(entry => entry.id !== id)];
    localStorage.setItem(REGISTRY_KEY, JSON.stringify(entries.slice(0, REMEMBERED)));
  } catch { /* Without the registry a closed tab is simply not found again, as before. */ }
}

const lockManager = () => typeof navigator === 'undefined' ? undefined : navigator.locks;
const lockName = (id: string) => `equinova:tab:${id}`;
// A lock lasts until its callback settles: this one is only released when the tab goes away.
const untilTabCloses = () => new Promise<never>(() => {});
function hold(id: string) {
  void lockManager()?.request(lockName(id), untilTabCloses).catch(() => {});
}
/** Take an identity only when no open tab holds it. Without Web Locks that cannot be proven. */
function takeOver(id: string): Promise<boolean> {
  const locks = lockManager();
  if (!locks) return Promise.resolve(false);
  return new Promise(resolve => {
    locks.request(lockName(id), { ifAvailable: true }, lock => {
      resolve(!!lock);
      return lock ? untilTabCloses() : undefined;
    }).catch(() => resolve(false));
  });
}

let claimed: Promise<string> | undefined;
/**
 * Settle this tab's identity before its database opens.
 *
 * Closing the app or a tab discards sessionStorage. Its unsynchronised answers and
 * photos then stayed behind under an identity nothing could find again. A tab that
 * starts without an identity therefore takes over the most recently used one that no
 * open tab holds, and continues with that database and its upload queue.
 *
 * A reloaded or restored tab keeps the identity it already has, as before.
 */
export function claimBrowserTab(): Promise<string> {
  claimed ??= claim().catch(() => browserTabId());
  return claimed;
}
async function claim(): Promise<string> {
  let id = sessionStorage.getItem(SESSION_KEY);
  if (id) hold(id);
  else {
    for (const entry of remembered()) {
      if (Date.now() - entry.at > TAKE_OVER_WITHIN_MS) break;
      if (await takeOver(entry.id)) { id = entry.id; break; }
    }
    if (!id) { id = crypto.randomUUID(); hold(id); }
    sessionStorage.setItem(SESSION_KEY, id);
  }
  const tab = id;
  remember(tab);
  // Keep the order of use current, so the tab that was closed last is found first.
  if (typeof addEventListener === 'function') addEventListener('pagehide', () => remember(tab));
  return tab;
}

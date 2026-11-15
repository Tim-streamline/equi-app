const SESSION_KEY = 'equinova:tab';
const REGISTRY_KEY = 'equinova:tabs';
const RECOVERED_KEY = 'equinova:tabs:recovered';
const REMEMBERED = 8;
// An older upload queue is more likely to overwrite newer answers than to recover lost ones.
const TAKE_OVER_WITHIN_MS = 30 * 24 * 60 * 60 * 1000;
// A reload releases the previous page's lock just before this page asks for it.
const RELOAD_GRACE_MS = 3000;
type Entry = { id: string; at: number };
/** What a signed-in tab needs to find the workspace an earlier version left behind. */
export type TabRecovery = {
  /** Recovery is settled per account: one account's result says nothing about another's. */
  account: string;
  /** Tabs whose stored photos of this account still wait for upload, the best candidate first. */
  waiting: () => Promise<string[]>;
  /** Whether no open tab, of any version, has this account's local data open. */
  idle: () => Promise<boolean>;
};

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
function flag(key: string, set?: true): boolean {
  try {
    if (set) localStorage.setItem(key, '1');
    return localStorage.getItem(key) !== null;
  } catch { return false; }
}

const lockManager = () => typeof navigator === 'undefined' ? undefined : navigator.locks;
/**
 * Hold an identity until this tab goes away. Resolves to false when another open tab
 * holds it. `waitMs` is how long that tab may take to let go; 0 does not wait at all.
 */
function acquire(id: string, waitMs = 0): Promise<boolean> {
  const locks = lockManager();
  if (!locks) return Promise.resolve(false);
  return new Promise(resolve => {
    const controller = waitMs ? new AbortController() : undefined;
    const timer = controller && setTimeout(() => controller.abort(), waitMs);
    const settle = (held: boolean) => { if (timer !== undefined) clearTimeout(timer); resolve(held); };
    locks.request(`equinova:tab:${id}`, controller ? { signal: controller.signal } : { ifAvailable: true }, lock => {
      settle(!!lock);
      // A lock lasts until its callback settles: this one is released when the tab closes.
      return lock ? new Promise<never>(() => {}) : undefined;
    }).catch(() => settle(false));
  });
}

/** Take over a workspace that no open tab uses, so the work left in it is not stranded. */
async function adopt(recovery: TabRecovery | undefined): Promise<string | null> {
  const registry = remembered();
  const recent = registry.filter(entry => Date.now() - entry.at <= TAKE_OVER_WITHIN_MS).map(entry => entry.id);
  // Tabs whose stored photos still wait for upload: known unsynchronised work.
  let stranded: string[] | undefined;
  try { stranded = await recovery?.waiting(); } catch { /* Discovery is retried on the next start. */ }
  const pending = new Set(stranded);
  for (const id of recent.filter(id => pending.has(id))) if (await acquire(id)) return id;
  // A tab closed by a version that kept no registry is only known by the photos it left.
  // Take one such workspace, once per account: replaying several old queues in turn would
  // put older answers over newer ones.
  const recovered = `${RECOVERED_KEY}:${recovery?.account}`;
  if (recovery && stranded && !flag(recovered)) {
    const known = new Set(registry.map(entry => entry.id));
    const unknown = stranded.filter(id => !known.has(id));
    if (!unknown.length) flag(recovered, true);
    // Those versions held no identity lock, so a free lock does not show that their tab is
    // closed. Only recover while no other tab has this account open; otherwise try again later.
    else if (await recovery.idle().catch(() => false)) {
      for (const id of unknown) if (await acquire(id)) { flag(recovered, true); return id; }
    }
  }
  for (const id of recent.filter(id => !pending.has(id))) if (await acquire(id)) return id;
  return null;
}

let claimed: Promise<string> | undefined;
/**
 * Settle this tab's identity before its database opens.
 *
 * Closing the app or a tab discards sessionStorage. Its unsynchronised answers and
 * photos then stayed behind under an identity nothing could find again. A tab that
 * starts without an identity therefore takes over a workspace that no open tab holds,
 * and continues with that database and its upload queue. A signed-in tab passes
 * `recovery`: workspaces whose stored photos still wait for upload are preferred.
 *
 * A reloaded or restored tab keeps its identity, unless another tab took that
 * workspace over while this one was gone: two tabs never share one database.
 * Without Web Locks none of this can be proven and identities work as before.
 */
export function claimBrowserTab(recovery?: TabRecovery): Promise<string> {
  claimed ??= claim(recovery).catch(() => browserTabId());
  return claimed;
}
async function claim(recovery?: TabRecovery): Promise<string> {
  let id = sessionStorage.getItem(SESSION_KEY);
  if (id && lockManager() && !await acquire(id, RELOAD_GRACE_MS)) id = null;
  if (!id) {
    id = await adopt(recovery);
    if (!id) { id = crypto.randomUUID(); void acquire(id); }
    sessionStorage.setItem(SESSION_KEY, id);
  }
  const tab = id;
  remember(tab);
  // Keep the order of use current, so the tab that was closed last is found first.
  if (typeof addEventListener === 'function') addEventListener('pagehide', () => remember(tab));
  return tab;
}

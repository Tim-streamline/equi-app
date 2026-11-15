const SESSION_KEY = 'equinova:tab';
const REGISTRY_KEY = 'equinova:tabs';
const RECOVERED_KEY = 'equinova:tabs:recovered';
const HISTORY_KEY = 'equinova:tabs:history';
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
    // An identity that is no longer remembered cannot be found again; its history goes too.
    // Removing only ever makes a history unknown, so this cannot race into a false one.
    for (const dropped of entries.slice(REMEMBERED)) forget(dropped.id);
  } catch { /* Without the registry a closed tab is simply not found again, as before. */ }
}
function flag(key: string, set?: true): boolean {
  try {
    if (set) localStorage.setItem(key, '1');
    return localStorage.getItem(key) !== null;
  } catch { return false; }
}

/**
 * Which accounts opened a database under each identity. Databases are named per identity
 * and account, so an identity with a complete history that lacks an account holds none of
 * its data. Identities this version did not mint have no entry: their history is unknown.
 */
type History = { complete: boolean; accounts: string[] };
// One key per identity, written only by the tab that holds that identity's lock. A shared
// map would let another tab write back an older copy of this identity's history.
const historyKey = (id: string) => `${HISTORY_KEY}:${id}`;
function historyOf(id: string): History | undefined {
  try {
    const { complete, accounts } = (JSON.parse(localStorage.getItem(historyKey(id)) ?? 'null') ?? {}) as Partial<History>;
    if (typeof complete === 'boolean' && Array.isArray(accounts) && accounts.every(account => typeof account === 'string')) {
      return { complete, accounts };
    }
  } catch { /* Unreadable: unknown. */ }
  return undefined;
}
function forget(id: string) {
  try { localStorage.removeItem(historyKey(id)); } catch { /* Blocked storage already reads as unknown. */ }
}
function record(id: string, history: History) {
  try { localStorage.setItem(historyKey(id), JSON.stringify(history)); }
  catch {
    // A history that could not be updated may claim an account never opened here. Forget it:
    // an unknown identity is never switched away from. Removing frees space, so it works
    // where the write above ran out of quota.
    forget(id);
  }
}

const lockManager = () => typeof navigator === 'undefined' ? undefined : navigator.locks;
type Release = () => void;
/**
 * Hold an identity until this tab goes away or the returned release is called. Resolves
 * to null when another open tab holds it. `waitMs` is how long that tab may take to let
 * go; 0 does not wait at all.
 */
function acquire(id: string, waitMs = 0): Promise<Release | null> {
  const locks = lockManager();
  if (!locks) return Promise.resolve(null);
  return new Promise(resolve => {
    const controller = waitMs ? new AbortController() : undefined;
    const timer = controller && setTimeout(() => controller.abort(), waitMs);
    const settle = (held: Release | null) => { if (timer !== undefined) clearTimeout(timer); resolve(held); };
    locks.request(`equinova:tab:${id}`, controller ? { signal: controller.signal } : { ifAvailable: true }, lock => {
      if (!lock) { settle(null); return undefined; }
      // A lock lasts until its callback settles: until release, or until the tab closes.
      return new Promise<void>(release => settle(() => release()));
    }).catch(() => settle(null));
  });
}

/** `recovered`: the per-account flag to set once a workspace left by an old version is taken. */
type Adopted = { id: string; release: Release; recovered?: string };
/** Tabs whose stored photos of this account still wait for upload; undefined when unknown. */
async function discover(recovery: TabRecovery | undefined): Promise<string[] | undefined> {
  try { return await recovery?.waiting(); } catch { return undefined; /* Discovery is retried on the next start. */ }
}
/**
 * Take over a workspace that no open tab uses, so the work left in it is not stranded.
 * `strandedOnly` limits this to workspaces known to hold waiting work of this account.
 */
async function adopt(recovery: TabRecovery | undefined, stranded: string[] | undefined, strandedOnly = false): Promise<Adopted | null> {
  const registry = remembered();
  const recent = registry.filter(entry => Date.now() - entry.at <= TAKE_OVER_WITHIN_MS).map(entry => entry.id);
  const pending = new Set(stranded);
  const take = async (id: string) => { const release = await acquire(id); return release ? { id, release } : null; };
  for (const id of recent.filter(id => pending.has(id))) { const held = await take(id); if (held) return held; }
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
      for (const id of unknown) {
        const held = await take(id);
        if (!held) continue;
        // A switch settles the flag only once it has committed to this workspace.
        if (!strandedOnly) flag(recovered, true);
        return { ...held, recovered };
      }
    }
  }
  if (strandedOnly) return null;
  for (const id of recent.filter(id => !pending.has(id))) { const held = await take(id); if (held) return held; }
  return null;
}

/**
 * A tab that kept its identity, but never opened this account in it, has none of the
 * account's data. When the account left work behind in a closed tab, continue there
 * instead: otherwise a tab that started signed out never finds that work after login.
 * Only switches when the history proves the account never opened here.
 */
async function switchToStranded(id: string, held: Release, recovery: TabRecovery): Promise<string> {
  const history = historyOf(id);
  if (!history?.complete || history.accounts.includes(recovery.account)) return id;
  const stranded = await discover(recovery);
  if (!stranded || stranded.includes(id)) return id;
  const candidate = await adopt(recovery, stranded, true);
  if (!candidate || candidate.id === id) return id;
  try { sessionStorage.setItem(SESSION_KEY, candidate.id); }
  catch { candidate.release(); return id; }
  if (candidate.recovered) flag(candidate.recovered, true);
  held();
  return candidate.id;
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
 * workspace over while this one was gone: two tabs never share one database. A kept
 * identity that never opened the signed-in account moves to that account's stranded work.
 * Without Web Locks none of this can be proven and identities work as before.
 */
export function claimBrowserTab(recovery?: TabRecovery): Promise<string> {
  claimed ??= claim(recovery).catch(() => browserTabId());
  return claimed;
}
async function claim(recovery?: TabRecovery): Promise<string> {
  let id = sessionStorage.getItem(SESSION_KEY);
  const held = id && lockManager() ? await acquire(id, RELOAD_GRACE_MS) : null;
  if (id && lockManager() && !held) id = null;
  if (id && held && recovery) id = await switchToStranded(id, held, recovery);
  // Only the tab holding an identity's lock writes its history: without that, two tabs
  // sharing an identity could write back a stale history over a newer one.
  let owned = !!(id && held);
  if (!id) {
    const adopted = await adopt(recovery, await discover(recovery));
    id = adopted?.id ?? null;
    owned = !!adopted;
    if (!id) {
      id = crypto.randomUUID();
      owned = !!await acquire(id);
      remember(id);
      // A new identity has opened nothing yet: its history is known to be complete.
      if (owned) record(id, { complete: true, accounts: [] });
    }
    sessionStorage.setItem(SESSION_KEY, id);
  }
  const tab = id;
  remember(tab);
  if (recovery && owned) {
    const history = historyOf(tab) ?? { complete: false, accounts: [] };
    if (!history.accounts.includes(recovery.account)) record(tab, { ...history, accounts: [...history.accounts, recovery.account] });
  }
  // Keep the order of use current, so the tab that was closed last is found first.
  if (typeof addEventListener === 'function') addEventListener('pagehide', () => remember(tab));
  return tab;
}

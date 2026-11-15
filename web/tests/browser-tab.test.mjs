import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { IDBFactory } from 'fake-indexeddb';
import ts from 'typescript';

function load(file, modules = {}, globals = {}) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  new Function('require', 'exports', ...Object.keys(globals), js)(name => {
    assert.ok(name in modules, name); return modules[name];
  }, exports, ...Object.values(globals));
  return exports;
}
const settle = async () => { for (let i = 0; i < 40; i++) await Promise.resolve(); };
const storage = (values = new Map()) => ({
  values, getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)),
  removeItem: key => values.delete(key),
});
/** What a signed-in tab passes: its account, the tabs with photos waiting, and whether the account is open elsewhere. */
const recovery = (waiting, options = {}) => ({ account: 'owner', idle: async () => true,
  waiting: typeof waiting === 'function' ? waiting : async () => waiting, ...options });
const attachments = (indexedDB, identity) => load('../../expo-app/lib/intake/attachments/storage.web.ts', {
  '../../browser-tab': identity, './events': { attachmentsChanged() {} },
  './storage-writes': load('../../expo-app/lib/intake/attachments/storage-writes.ts'),
}, { indexedDB });

/** One browser profile: localStorage and Web Locks are shared by its tabs, sessionStorage is not. */
function browser({ locks = true } = {}) {
  const local = storage(), held = new Map(), queue = [], timers = new Set();
  const release = (name, owner) => {
    if (held.get(name) !== owner) return;
    held.delete(name);
    const next = queue.findIndex(waiter => waiter.name === name);
    if (next >= 0) queue.splice(next, 1)[0].grant();
  };
  // Each load is a separate tab runtime. Closing it releases its locks, as the browser does.
  function open(session = storage()) {
    const owner = {}, hidden = [];
    const manager = { request(name, options, callback = options) {
      if (typeof options === 'function') options = {};
      return new Promise((resolve, reject) => {
        const grant = () => {
          held.set(name, owner);
          Promise.resolve().then(() => callback({ name })).then(resolve, reject).finally(() => release(name, owner));
        };
        if (!held.has(name)) return grant();
        if (options.ifAvailable) return Promise.resolve(callback(null)).then(resolve, reject);
        const waiter = { name, owner, grant };
        queue.push(waiter);
        options.signal?.addEventListener('abort', () => {
          const index = queue.indexOf(waiter);
          if (index >= 0) { queue.splice(index, 1); reject(new DOMException('The request was aborted.', 'AbortError')); }
        });
      });
    } };
    const identity = load('../../expo-app/lib/browser-tab.ts', {}, {
      sessionStorage: session, localStorage: local, navigator: locks ? { locks: manager } : {},
      addEventListener: (type, listener) => { if (type === 'pagehide') hidden.push(listener); },
      setTimeout: (run, ms) => { const timer = { run, ms }; timers.add(timer); return timer; },
      clearTimeout: timer => timers.delete(timer),
    });
    const close = () => {
      hidden.forEach(listener => listener());
      for (let i = queue.length - 1; i >= 0; i--) if (queue[i].owner === owner) queue.splice(i, 1);
      for (const [name, tab] of held) if (tab === owner) release(name, owner);
    };
    return { ...identity, session, close };
  }
  /** Let every waiting period that was started run out. */
  const wait = async () => { await settle(); for (const timer of [...timers]) { timers.delete(timer); timer.run(); } await settle(); };
  return { open, local, wait };
}

test('a restarted app continues with the identity it left behind', async () => {
  const profile = browser(), first = profile.open();
  const id = await first.claimBrowserTab();
  assert.equal(first.browserTabId(), id);
  first.close(); // The app is closed: its sessionStorage is gone, localStorage is not.
  const restarted = profile.open();
  assert.equal(await restarted.claimBrowserTab(), id);
  assert.equal(restarted.browserTabId(), id);
  assert.equal(await restarted.claimBrowserTab(), id, 'the identity is settled once per tab');
});

test('an identity held by an open tab is never shared with a new tab', async () => {
  const profile = browser(), first = profile.open();
  const id = await first.claimBrowserTab();
  const second = profile.open(), other = await second.claimBrowserTab();
  assert.notEqual(other, id);
  // The most recently closed tab is found first; the open one is still skipped.
  second.close();
  assert.equal(await profile.open().claimBrowserTab(), other);
  const fourth = profile.open(), fresh = await fourth.claimBrowserTab();
  assert.ok(fresh !== id && fresh !== other);
});

test('a reloaded tab keeps its own identity, also when the previous page lets go a moment later', async () => {
  const profile = browser(), first = profile.open();
  const id = await first.claimBrowserTab();
  const second = profile.open(); await second.claimBrowserTab(); second.close();
  first.close();
  const reloaded = profile.open(first.session);
  assert.equal(await reloaded.claimBrowserTab(), id);
  // The page being replaced still holds the lock while its successor already asks for it.
  const again = profile.open(first.session), claiming = again.claimBrowserTab();
  await settle(); reloaded.close();
  assert.equal(await claiming, id);
});

test('a restored or duplicated tab does not share a workspace that another open tab holds', async () => {
  const profile = browser(), original = profile.open();
  const id = await original.claimBrowserTab();
  original.close(); // The browser discarded the tab, but keeps its sessionStorage to restore it.
  const adopter = profile.open();
  assert.equal(await adopter.claimBrowserTab(), id, 'a new tab took the workspace over');
  const restored = profile.open(original.session), restoring = restored.claimBrowserTab();
  await profile.wait();
  const moved = await restoring;
  assert.notEqual(moved, id, 'the restored tab must not open the same database');
  assert.equal(restored.browserTabId(), moved);
  assert.equal(adopter.browserTabId(), id);
  // A duplicated tab starts with a copy of the sessionStorage of the tab it was made from.
  const duplicate = profile.open(storage(new Map(adopter.session.values))), duplicating = duplicate.claimBrowserTab();
  await profile.wait();
  assert.ok(![id, moved].includes(await duplicating));
});

test('without Web Locks or with an old registry entry a new tab starts with a new identity', async () => {
  const unsupported = browser({ locks: false }), first = unsupported.open();
  const id = await first.claimBrowserTab();
  first.close();
  assert.notEqual(await unsupported.open().claimBrowserTab(recovery([id])), id, 'ownership cannot be proven without Web Locks');
  assert.equal(await unsupported.open(first.session).claimBrowserTab(), id, 'a reload still keeps its identity, as before');

  const profile = browser(), stale = '11111111-1111-4111-8111-111111111111';
  profile.local.setItem('equinova:tabs', JSON.stringify([{ id: stale, at: Date.now() - 31 * 24 * 60 * 60 * 1000 }]));
  assert.notEqual(await profile.open().claimBrowserTab(), stale);
  profile.local.setItem('equinova:tabs', 'not json');
  assert.match(await profile.open().claimBrowserTab(), /^[0-9a-f-]{36}$/);
  profile.local.setItem = () => { throw new DOMException('blocked', 'SecurityError'); };
  assert.match(await profile.open().claimBrowserTab(), /^[0-9a-f-]{36}$/, 'blocked storage must not stop the app');
});

test('workspaces with photos still waiting for upload are taken over first; an unregistered one only once', async () => {
  const profile = browser();
  const older = profile.open(), olderId = await older.claimBrowserTab();
  const newer = profile.open(), newerId = await newer.claimBrowserTab();
  assert.notEqual(newerId, olderId);
  older.close(); newer.close();
  const unregistered = ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
  // The older registered workspace still has photos waiting: that is known unsynchronised work.
  const first = profile.open();
  assert.equal(await first.claimBrowserTab(recovery([...unregistered, olderId])), olderId);
  // Then one workspace that an earlier version left without a registry entry, the newest first.
  const second = profile.open();
  assert.equal(await second.claimBrowserTab(recovery(unregistered)), unregistered[0]);
  // The other old queues are not replayed in turn: they would put older answers over newer ones.
  const third = profile.open();
  assert.equal(await third.claimBrowserTab(recovery(unregistered)), newerId);
  first.close(); second.close(); third.close();
  assert.ok(!unregistered.slice(1).includes(await profile.open().claimBrowserTab(recovery(unregistered.slice(1)))));
  // A discovery that finds nothing to recover is final too: this version registers every workspace.
  const clean = browser(), started = clean.open();
  await started.claimBrowserTab(recovery([])); started.close();
  assert.equal(await clean.open().claimBrowserTab(recovery(unregistered)), started.browserTabId());
  // A failing discovery never stops the app, and is not counted as a finished recovery.
  const other = browser(), failing = other.open();
  assert.match(await failing.claimBrowserTab(recovery(async () => { throw new Error('IndexedDB unavailable'); })), /^[0-9a-f-]{36}$/);
  failing.close();
  assert.equal(await other.open().claimBrowserTab(recovery(['cccccccc-cccc-4ccc-8ccc-cccccccccccc', failing.browserTabId()])), failing.browserTabId());
  assert.equal(await other.open().claimBrowserTab(recovery(['cccccccc-cccc-4ccc-8ccc-cccccccccccc'])), 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');
});

test('an old workspace is not taken over while another tab has the account open, and recovery is settled per account', async () => {
  const stranded = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', others = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
  // A tab of the old version holds no identity lock: a free lock does not show that it is closed.
  const profile = browser(), busy = profile.open();
  const fresh = await busy.claimBrowserTab(recovery([stranded], { idle: async () => false }));
  assert.notEqual(fresh, stranded, 'the old tab may still have this database open');
  busy.close();
  // Not recovering yet is not the same as having nothing to recover: the next start tries again.
  const later = profile.open();
  assert.equal(await later.claimBrowserTab(recovery([stranded])), stranded);
  later.close();
  // A check that fails is treated as "in use"; a signed-out start does not look at all.
  const unsure = browser();
  assert.notEqual(await unsure.open().claimBrowserTab(recovery([stranded], { idle: async () => { throw new Error('locks unavailable'); } })), stranded);
  const signedOut = unsure.open(), anonymous = await signedOut.claimBrowserTab();
  assert.notEqual(anonymous, stranded); signedOut.close();

  // Account A has nothing waiting. That must not use up the recovery of account B on this device.
  const shared = browser(), a = shared.open();
  const aId = await a.claimBrowserTab(recovery([], { account: 'account-a' })); a.close();
  const b = shared.open();
  assert.equal(await b.claimBrowserTab(recovery([others], { account: 'account-b' })), others);
  b.close();
  // Each account still recovers only once.
  const again = shared.open();
  assert.equal(await again.claimBrowserTab(recovery(['ffffffff-ffff-4fff-8fff-ffffffffffff'], { account: 'account-b' })), others);
  assert.notEqual(aId, others);
});

test('a photo that was not uploaded yet is still there after the app is closed and started again', async () => {
  const profile = browser(), indexedDB = new IDBFactory();
  const id = '22222222-2222-4222-8222-222222222222';
  const first = profile.open();
  await first.claimBrowserTab();
  await attachments(indexedDB, first).saveAttachment({ id, userId: 'owner', bookingId: 'intake', horseId: 'horse', section: 'photos',
    field: 'right-hind', name: 'hoof.jpg', mime: 'image/jpeg', uploaded: false }, { file: new Blob(['hoof photo']) });
  first.close();
  const restarted = profile.open();
  await restarted.claimBrowserTab();
  const record = await attachments(indexedDB, restarted).attachmentStorage.get('owner', id);
  assert.equal(record.uploaded, false);
  assert.equal(await record.blob.text(), 'hoof photo');
});

test('photos stranded by a version without a registry are found again after the update', async () => {
  const profile = browser(), indexedDB = new IDBFactory();
  const photo = (id, userId = 'owner') => ({ id, userId, bookingId: 'intake', horseId: 'horse', section: 'photos',
    field: 'right-hind', name: 'hoof.jpg', mime: 'image/jpeg', uploaded: false });
  const file = (bytes, lastModified) => new File([bytes], 'hoof.jpg', { type: 'image/jpeg', lastModified });
  // The old version only knew browserTabId(): it kept no registry and held no lock.
  const sessions = { uploaded: 1_000, first: 2_000, latest: 3_000, refused: 4_000, other: 5_000 };
  const ids = Object.fromEntries(Object.keys(sessions).map((name, index) => [name, `3333333${index}-3333-4333-8333-333333333333`]));
  const tabs = {};
  for (const [name, taken] of Object.entries(sessions)) {
    const old = profile.open(), store = attachments(indexedDB, old);
    tabs[name] = old.browserTabId();
    await store.saveAttachment(photo(ids[name], name === 'other' ? 'someone-else' : 'owner'), { file: file(`${name} bytes`, taken) });
    const saved = await store.attachmentStorage.get(name === 'other' ? 'someone-else' : 'owner', ids[name]);
    if (name === 'uploaded') await store.attachmentStorage.put({ ...saved, uploaded: true });
    if (name === 'refused') await store.attachmentStorage.put({ ...saved, rejections: 3, rejected: true });
    old.close();
  }
  const updated = profile.open(), store = attachments(indexedDB, updated);
  assert.deepEqual(await store.waitingAttachmentTabs('owner'), [tabs.latest, tabs.first], 'newest waiting photo first; uploaded, refused and other accounts left out');
  assert.deepEqual(await store.waitingAttachmentTabs('someone-else'), [tabs.other]);
  assert.equal(await updated.claimBrowserTab(recovery(() => store.waitingAttachmentTabs('owner'))), tabs.latest);
  const record = await attachments(indexedDB, updated).attachmentStorage.get('owner', ids.latest);
  assert.equal(await record.blob.text(), 'latest bytes');
});

test('a tab that started signed out continues with the stranded work of the account that signs in', async () => {
  const legacy = '44444444-4444-4444-8444-444444444444';
  // Photos left by an old version that kept no registry: a signed-out start does not look for them.
  const profile = browser(), signedOut = profile.open();
  const anonymous = await signedOut.claimBrowserTab();
  assert.notEqual(anonymous, legacy);
  signedOut.close();
  // Login replaces the document; the tab keeps its sessionStorage and so its identity.
  const loggedIn = profile.open(signedOut.session);
  assert.equal(await loggedIn.claimBrowserTab(recovery([legacy])), legacy);
  assert.equal(loggedIn.browserTabId(), legacy);
  // The identity it left is free again for another tab.
  assert.equal(await profile.open().claimBrowserTab(), anonymous);

  // The same for a registered workspace that another tab still held when this tab started.
  const other = browser(), holder = other.open();
  const owned = await holder.claimBrowserTab(recovery([]));
  const waiting = other.open(), fresh = await waiting.claimBrowserTab();
  assert.notEqual(fresh, owned);
  holder.close(); waiting.close();
  const after = other.open(waiting.session);
  assert.equal(await after.claimBrowserTab(recovery([owned])), owned);
});

test('a kept identity is only left when its history proves the account never opened there', async () => {
  const legacy = '55555555-5555-4555-8555-555555555555';
  // It already opened this account: its own database holds the account's work.
  const profile = browser(), used = profile.open();
  const id = await used.claimBrowserTab(recovery([]));
  used.close();
  const reloaded = profile.open(used.session);
  assert.equal(await reloaded.claimBrowserTab(recovery([legacy])), id);
  reloaded.close();
  // A signed-out reload does not erase what the identity opened before.
  const anonymous = profile.open(used.session);
  assert.equal(await anonymous.claimBrowserTab(), id);
  anonymous.close();
  assert.equal(await profile.open(used.session).claimBrowserTab(recovery([legacy])), id);

  // A signed-out start that took over an older workspace does not know what that workspace held.
  const old = '66666666-6666-4666-8666-666666666666';
  const adopted = browser();
  adopted.local.setItem('equinova:tabs', JSON.stringify([{ id: old, at: Date.now() }]));
  const start = adopted.open();
  assert.equal(await start.claimBrowserTab(), old);
  start.close();
  assert.equal(await adopted.open(start.session).claimBrowserTab(recovery([legacy])), old);

  // A session from a version without history may hold another account's data: B does not move it.
  const session = storage(new Map([['equinova:tab', old]]));
  assert.equal(await browser().open(session).claimBrowserTab(recovery([legacy], { account: 'account-b' })), old);

  // Photos of this account waiting in the kept identity itself: it stays.
  const own = browser(), first = own.open();
  const mine = await first.claimBrowserTab();
  first.close();
  assert.equal(await own.open(first.session).claimBrowserTab(recovery([mine, legacy])), mine);
});

test('a switch that cannot take the stranded workspace or cannot be stored keeps the identity', async () => {
  // Another open tab holds the stranded workspace.
  const profile = browser(), holder = profile.open();
  const owned = await holder.claimBrowserTab(recovery([]));
  const tab = profile.open(), fresh = await tab.claimBrowserTab();
  tab.close();
  assert.equal(await profile.open(tab.session).claimBrowserTab(recovery([owned])), fresh);

  // sessionStorage refuses the new identity: stay, and leave the stranded workspace free and unsettled.
  const legacy = '77777777-7777-4777-8777-777777777777';
  const blocked = browser(), first = blocked.open();
  const id = await first.claimBrowserTab();
  first.close();
  const session = storage(new Map(first.session.values));
  session.setItem = () => { throw new DOMException('blocked', 'QuotaExceededError'); };
  assert.equal(await blocked.open(session).claimBrowserTab(recovery([legacy])), id);
  assert.equal(await blocked.open().claimBrowserTab(recovery([legacy])), legacy, 'the old workspace can still be recovered');
});

test('a history that cannot be updated is forgotten, so the tab never leaves work behind', async () => {
  // Signed out: a new identity with a complete, empty history.
  const profile = browser(), holder = profile.open();
  const owned = await holder.claimBrowserTab(recovery([]));
  const tab = profile.open(), fresh = await tab.claimBrowserTab();
  tab.close();
  // Sign in while the stranded workspace is still held elsewhere, and the history write fails.
  const setItem = profile.local.setItem;
  profile.local.setItem = (key, value) => {
    if (key.startsWith('equinova:tabs:history:')) throw new DOMException('full', 'QuotaExceededError');
    return setItem(key, value);
  };
  const signedIn = profile.open(tab.session);
  assert.equal(await signedIn.claimBrowserTab(recovery([owned])), fresh);
  profile.local.setItem = setItem;
  signedIn.close(); holder.close();
  // The account opened here and may have left offline edits: once the workspace is free, stay.
  assert.equal(await profile.open(tab.session).claimBrowserTab(recovery([owned])), fresh);
});

test('without Web Locks no history is written, so the identity stays unknown', async () => {
  // Duplicated tabs can share one identity here; neither may claim to know its history.
  const profile = browser({ locks: false }), tab = profile.open();
  const id = await tab.claimBrowserTab();
  tab.close();
  assert.equal(await profile.open(tab.session).claimBrowserTab(recovery([])), id);
  assert.deepEqual([...profile.local.values.keys()].filter(key => key.startsWith('equinova:tabs:history:')), []);
});

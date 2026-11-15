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
const storage = (values = new Map()) => ({
  values, getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)),
});

/** One browser profile: localStorage and Web Locks are shared by its tabs, sessionStorage is not. */
function browser({ locks = true } = {}) {
  const local = storage(), held = new Map();
  // Each load is a separate tab runtime. Closing it releases its locks, as the browser does.
  function open(session = storage()) {
    const owner = {}, hidden = [];
    const manager = { async request(name, options, callback = options) {
      if (held.has(name)) {
        if (options.ifAvailable) return callback(null);
        return new Promise(() => {}); // Queued behind the tab that holds it.
      }
      held.set(name, owner);
      try { return await callback({ name }); } finally { held.delete(name); }
    } };
    const identity = load('../../expo-app/lib/browser-tab.ts', {}, {
      sessionStorage: session, localStorage: local, navigator: locks ? { locks: manager } : {},
      addEventListener: (type, listener) => { if (type === 'pagehide') hidden.push(listener); },
    });
    const close = () => {
      hidden.forEach(listener => listener());
      for (const [name, tab] of held) if (tab === owner) held.delete(name);
    };
    return { ...identity, session, close };
  }
  return { open, local };
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

test('a reloaded tab keeps its own identity, also while another tab uses the app', async () => {
  const profile = browser(), first = profile.open();
  const id = await first.claimBrowserTab();
  const second = profile.open(); await second.claimBrowserTab(); second.close();
  first.close();
  assert.equal(await profile.open(first.session).claimBrowserTab(), id);
});

test('without Web Locks or with an old registry entry a new tab starts with a new identity', async () => {
  const unsupported = browser({ locks: false }), first = unsupported.open();
  const id = await first.claimBrowserTab();
  first.close();
  assert.notEqual(await unsupported.open().claimBrowserTab(), id, 'ownership cannot be proven without Web Locks');

  const profile = browser(), stale = '11111111-1111-4111-8111-111111111111';
  profile.local.setItem('equinova:tabs', JSON.stringify([{ id: stale, at: Date.now() - 31 * 24 * 60 * 60 * 1000 }]));
  assert.notEqual(await profile.open().claimBrowserTab(), stale);
  profile.local.setItem('equinova:tabs', 'not json');
  assert.match(await profile.open().claimBrowserTab(), /^[0-9a-f-]{36}$/);
  profile.local.setItem = () => { throw new DOMException('blocked', 'SecurityError'); };
  assert.match(await profile.open().claimBrowserTab(), /^[0-9a-f-]{36}$/, 'blocked storage must not stop the app');
});

test('a photo that was not uploaded yet is still there after the app is closed and started again', async () => {
  const profile = browser(), indexedDB = new IDBFactory();
  const attachments = identity => load('../../expo-app/lib/intake/attachments/storage.web.ts', {
    '../../browser-tab': identity, './events': { attachmentsChanged() {} },
    './storage-writes': load('../../expo-app/lib/intake/attachments/storage-writes.ts'),
  }, { indexedDB });
  const id = '22222222-2222-4222-8222-222222222222';
  const first = profile.open();
  await first.claimBrowserTab();
  await attachments(first).saveAttachment({ id, userId: 'owner', bookingId: 'intake', horseId: 'horse', section: 'photos',
    field: 'right-hind', name: 'hoof.jpg', mime: 'image/jpeg', uploaded: false }, { file: new Blob(['hoof photo']) });
  first.close();
  const restarted = profile.open();
  await restarted.claimBrowserTab();
  const record = await attachments(restarted).attachmentStorage.get('owner', id);
  assert.equal(record.uploaded, false);
  assert.equal(await record.blob.text(), 'hoof photo');
});

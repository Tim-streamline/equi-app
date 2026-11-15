import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import { IDBFactory } from 'fake-indexeddb';
import ts from 'typescript';
import { flushAttachmentUploads } from '../../expo-app/lib/intake/attachments/upload.ts';

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

const shared = '../../expo-app/lib/';
const id = '11111111-1111-4111-8111-111111111111';
const photo = (userId = 'owner', bytes = 'photo bytes') => ({
  id, userId, bookingId: 'intake', horseId: 'horse', section: 'section', field: 'photo',
  name: 'horse.jpg', mime: 'image/jpeg', uploaded: false, blob: new Blob([bytes]),
});
const operations = [{ table: 'intake_answers', opData: { value: JSON.stringify([`attachment:${id}:horse.jpg`]) } }];

// Each load is a separate tab/runtime; IndexedDB and a restored tab's session
// storage survive closing it. The production tab identity helper is used by both
// the attachment module and the database provider.
function tab(indexedDB, session = new Map()) {
  const identity = load(`${shared}browser-tab.ts`, {}, { sessionStorage: {
    getItem: key => session.get(key) ?? null, setItem: (key, value) => session.set(key, value),
  } });
  const storage = load(`${shared}intake/attachments/storage.web.ts`, {
    '../../browser-tab': identity,
    './events': { attachmentsChanged() {} },
    './storage-writes': load(`${shared}intake/attachments/storage-writes.ts`),
  }, { indexedDB });
  const { clearLocalSyncData } = load('../../expo-app/db/reset-local-data.ts', {
    '@/lib/intake/attachments/storage': storage,
    '@react-native-async-storage/async-storage': { default: { getAllKeys: async () => [], multiRemove: async () => {} } },
  });
  return { ...storage, ...identity, session, clearLocalSyncData };
}

async function legacyRecord(indexedDB, record) {
  const db = await new Promise((resolve, reject) => {
    const request = indexedDB.open('equi-intake-attachments', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('files', { keyPath: ['userId', 'id'] });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  try {
    await new Promise((resolve, reject) => {
      const tx = db.transaction('files', 'readwrite');
      tx.oncomplete = resolve; tx.onabort = () => reject(tx.error);
      tx.objectStore('files').put(record);
    });
  } finally { db.close(); }
}

test('attachment bytes and status are isolated by user and persistent database tab identity', async () => {
  const indexedDB = new IDBFactory(), a = tab(indexedDB), b = tab(indexedDB);
  assert.notEqual(a.browserTabId(), b.browserTabId());
  assert.equal(a.session.get('equinova:tab'), a.browserTabId());
  await a.attachmentStorage.put(photo());
  assert.equal(await b.attachmentStorage.get('owner', id), null);
  await b.attachmentStorage.put(photo('owner', 'tab B bytes'));
  await a.attachmentStorage.put(photo('other', 'other user bytes'));
  const restoredA = tab(indexedDB, a.session);
  assert.equal(restoredA.browserTabId(), a.browserTabId());
  assert.equal(await (await restoredA.attachmentStorage.get('owner', id)).blob.text(), 'photo bytes');
  await restoredA.clearLocalSyncData('owner');
  assert.equal(await restoredA.attachmentStorage.get('owner', id), null);
  assert.equal(await (await b.attachmentStorage.get('owner', id)).blob.text(), 'tab B bytes');
  assert.equal(await (await restoredA.attachmentStorage.get('other', id)).blob.text(), 'other user bytes');
});

test('closed tab B retains its queued photo and uploads it after tab A resets', async t => {
  const directory = mkdtempSync(join(tmpdir(), 'equi-tab-attachments-'));
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  const queuePath = join(directory, 'tab-b.db');
  const indexedDB = new IDBFactory(), a = tab(indexedDB), sessionB = new Map();
  // Persist both the queue reference and bytes, then close B's database/runtime.
  {
    const b = tab(indexedDB, sessionB), queue = new DatabaseSync(queuePath);
    try {
      await b.saveAttachment(photo(), { file: photo().blob });
      queue.exec('CREATE TABLE pending (operation TEXT NOT NULL)');
      queue.prepare('INSERT INTO pending VALUES (?)').run(JSON.stringify(operations[0]));
    } finally { queue.close(); }
  }
  await a.saveAttachment(photo('owner', 'discard A'), { file: new Blob(['discard A']) });
  await a.clearLocalSyncData('owner');
  assert.equal(await a.attachmentStorage.get('owner', id), null);
  const restoredB = tab(indexedDB, sessionB), queue = new DatabaseSync(queuePath);
  try {
    const pending = queue.prepare('SELECT operation FROM pending').all().map(row => JSON.parse(row.operation));
    const uploads = [];
    await flushAttachmentUploads(pending, 'owner', restoredB.attachmentStorage, async record => {
      uploads.push({ id: record.id, bytes: await record.blob.text() });
    }, () => {});
    assert.deepEqual(uploads, [{ id, bytes: 'photo bytes' }]);
    assert.equal((await restoredB.attachmentStorage.get('owner', id)).uploaded, true);
  } finally { queue.close(); }
});

test('legacy files remain readable and unchanged after copy-on-write and a different tab reset', async () => {
  const indexedDB = new IDBFactory(), a = tab(indexedDB), sessionB = new Map();
  await legacyRecord(indexedDB, photo());
  const b = tab(indexedDB, sessionB);
  assert.equal(await (await b.attachmentStorage.get('owner', id)).blob.text(), 'photo bytes');
  assert.equal(await b.attachmentStorage.get('other', id), null);
  // Updating a legacy record only changes A's copy. B's upload remains pending.
  const original = await a.attachmentStorage.get('owner', id);
  await a.attachmentStorage.put({ ...original, uploaded: true });
  assert.equal((await a.attachmentStorage.get('owner', id)).uploaded, true);
  assert.equal((await b.attachmentStorage.get('owner', id)).uploaded, false);
  await a.clearLocalSyncData('owner');
  const restoredB = tab(indexedDB, sessionB), uploads = [];
  await flushAttachmentUploads(operations, 'owner', restoredB.attachmentStorage,
    async record => { uploads.push(await record.blob.text()); }, () => {});
  assert.deepEqual(uploads, ['photo bytes']);
  assert.equal((await restoredB.attachmentStorage.get('owner', id)).uploaded, true);
  // A fresh tab still sees the untouched legacy original, not B's scoped status.
  const legacy = await tab(indexedDB).attachmentStorage.get('owner', id);
  assert.equal(legacy.uploaded, false);
  assert.equal(await legacy.blob.text(), 'photo bytes');
});

test('a picker read completed after reset cannot recreate the discarded file', async () => {
  const indexedDB = new IDBFactory(), a = tab(indexedDB);
  let finish;
  const reading = new Promise(resolve => { finish = resolve; });
  const asset = { get file() { return undefined; }, uri: 'photo:pending' };
  // Load the production module with a delayed picker fetch, in its own tab.
  const storage = load(`${shared}intake/attachments/storage.web.ts`, {
    '../../browser-tab': { browserTabId: a.browserTabId },
    './events': { attachmentsChanged() {} },
    './storage-writes': load(`${shared}intake/attachments/storage-writes.ts`),
  }, { indexedDB, fetch: () => reading });
  const saving = storage.saveAttachment(photo(), asset);
  const rejected = assert.rejects(saving, /opnieuw ingesteld/);
  await storage.clearAttachments('owner');
  finish({ blob: async () => new Blob(['late bytes']) });
  await rejected;
  assert.equal(await storage.attachmentStorage.get('owner', id), null);
});

function rows(indexedDB, name, store) {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(name, 1);
    request.onupgradeneeded = () => reject(new Error(`${name} was never written`));
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const all = request.result.transaction(store, 'readonly').objectStore(store).getAll();
      all.onsuccess = () => { request.result.close(); resolve(all.result); };
      all.onerror = () => reject(all.error);
    };
  });
}

test('recording an upload attempt stores only its status and never rewrites the photo', async () => {
  const indexedDB = new IDBFactory(), a = tab(indexedDB);
  await a.saveAttachment(photo(), { file: photo().blob });
  // Safari can fail to store a file that it read from IndexedDB, so the bytes are written once.
  const failed = await a.attachmentStorage.get('owner', id);
  await a.attachmentStorage.put({ ...failed, error: 'Uploaden is niet gelukt.' });
  assert.equal((await a.attachmentStorage.get('owner', id)).error, 'Uploaden is niet gelukt.');
  const pending = await a.attachmentStorage.get('owner', id);
  await a.attachmentStorage.put({ ...pending, uploaded: true, error: undefined });
  const uploaded = await a.attachmentStorage.get('owner', id);
  assert.equal(uploaded.uploaded, true); assert.equal(uploaded.error, undefined);
  assert.equal(await uploaded.blob.text(), 'photo bytes');
  const files = await rows(indexedDB, 'equi-intake-attachments-scoped', 'files');
  assert.equal(files.length, 1);
  assert.equal(files[0].uploaded, false, 'the stored photo row is untouched by status updates');
  assert.equal((await rows(indexedDB, 'equi-intake-attachment-status', 'status')).length, 1);
  // A refusal by the server is part of the status and survives closing the tab.
  await a.attachmentStorage.put({ ...uploaded, uploaded: false, error: 'Geweigerd.', rejections: 3, rejected: true });
  const refused = await tab(indexedDB, a.session).attachmentStorage.get('owner', id);
  assert.deepEqual([refused.uploaded, refused.rejections, refused.rejected, refused.error], [false, 3, true, 'Geweigerd.']);
  // Bytes chosen again replace the photo together with the status that described the old bytes.
  await a.saveAttachment(photo('owner', 'chosen again'), { file: new Blob(['chosen again']) });
  const replaced = await a.attachmentStorage.get('owner', id);
  assert.equal(replaced.uploaded, false); assert.equal(await replaced.blob.text(), 'chosen again');
  assert.equal(replaced.rejected, undefined);
  await a.attachmentStorage.put({ ...replaced, uploaded: true });
  await a.clearLocalSyncData('owner');
  assert.equal(await a.attachmentStorage.get('owner', id), null);
  assert.deepEqual(await rows(indexedDB, 'equi-intake-attachment-status', 'status'), []);
});

test('a failed storage request rejects with an error, never with null', async () => {
  // A request error reaches the transaction before it aborts: tx.error is still null then.
  const cause = { error: null };
  const indexedDB = { open() {
    const tx = { error: null, objectStore: () => ({
      put: () => queueMicrotask(() => tx.onerror({ target: cause })),
      delete: () => queueMicrotask(() => tx.oncomplete()),
    }) };
    const request = { result: { close() {}, transaction: () => tx } };
    queueMicrotask(() => request.onsuccess());
    return request;
  } };
  const storage = load(`${shared}intake/attachments/storage.web.ts`, {
    '../../browser-tab': { browserTabId: () => 'tab' }, './events': { attachmentsChanged() {} },
    './storage-writes': load(`${shared}intake/attachments/storage-writes.ts`),
  }, { indexedDB });
  await assert.rejects(storage.attachmentStorage.put(photo()), error => error instanceof Error && /kon niet op dit apparaat/.test(error.message));
  cause.error = new DOMException('Error preparing Blob/File data to be stored in object store', 'UnknownError');
  await assert.rejects(storage.attachmentStorage.put(photo()), error => error === cause.error);
});

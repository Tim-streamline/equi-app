import * as syncOperations from '../lib/intake/sync-operations.ts';
import test from 'node:test';
import assert from 'node:assert/strict';
import { attachmentIds, flushAttachmentUploads } from '../lib/intake/attachments/upload.ts';
const id = '11111111-1111-4111-8111-111111111111';
const reference = `attachment:${id}:horse.jpg`;
const operations = [{ table: 'intake_answers', opData: { value: JSON.stringify([reference]) } }];
function fixture() {
  const records = new Map([[`owner:${id}`, { id, userId: 'owner', bookingId: 'intake', uploaded: false, blob: new Blob(['photo bytes']), name: 'horse.jpg' }]]);
  return { records, storage: { get: async (user, id) => records.get(`${user}:${id}`) ?? null,
    put: async record => records.set(`${record.userId}:${record.id}`, record) } };
}

test('offline failure retains bytes and ID; reconnect retries before answers can sync', async () => {
  const { records, storage } = fixture();
  await assert.rejects(flushAttachmentUploads(operations, 'owner', storage, async () => { throw new Error('offline'); }, () => {}), /offline/);
  assert.equal(records.get(`owner:${id}`).uploaded, false);
  assert.equal(await records.get(`owner:${id}`).blob.text(), 'photo bytes');
  const uploads = [];
  await flushAttachmentUploads(operations, 'owner', storage, async r => { uploads.push(r.id); }, () => {});
  assert.deepEqual(uploads, [id]);
  assert.equal(records.get(`owner:${id}`).uploaded, true);
  assert.equal(records.get(`owner:${id}`).error, undefined);
  await flushAttachmentUploads(operations, 'owner', storage, async () => assert.fail('already uploaded'), () => {});
  assert.equal(operations[0].opData.value, JSON.stringify([reference]));
});

test('a lost response replays the same ID; answers stay queued until upload is acknowledged', async () => {
  const { storage } = fixture();
  const attempts = [];
  const upload = async record => { attempts.push(record.id); if (attempts.length === 1) throw new Error('response lost'); };
  await assert.rejects(flushAttachmentUploads(operations, 'owner', storage, upload, () => {}));
  await flushAttachmentUploads(operations, 'owner', storage, upload, () => {});
  assert.deepEqual(attempts, [id, id]);
});

test('owner isolation and session changes prevent another account from acknowledging uploads', async () => {
  const { storage, records } = fixture();
  await flushAttachmentUploads(operations, 'other', storage, async () => assert.fail('cross account upload'), () => {});
  let switched = false;
  await assert.rejects(flushAttachmentUploads(operations, 'owner', storage, async () => { switched = true; }, () => {
    if (switched) throw new Error('session changed');
  }), /session changed/);
  assert.equal(records.get(`owner:${id}`).uploaded, false);
  await assert.rejects(flushAttachmentUploads(operations, 'other', { ...storage, get: async () => records.get(`owner:${id}`) }, async () => assert.fail(), () => {}), /ander account/);
});

test('only attachment arrays schedule uploads, with IDs deduplicated', () => {
  assert.deepEqual(attachmentIds([...operations, ...operations,
    { table: 'intake_answers', opData: { value: JSON.stringify('2024') } },
    { table: 'intake_answers', opData: { value: 'invalid json' } },
    { table: 'intake_answers', opData: null },
    { table: 'intake_answers', opData: { value: JSON.stringify(['old.jpg', 'attachment:invalid:photo']) } },
    { table: 'community_posts', opData: operations[0].opData },
  ]), [id]);
});

// Exercise both production connectors: binary upload must finish before JSON/ack.
import { readFileSync } from 'node:fs';
import ts from 'typescript';
function load(file, modules) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  new Function('require', 'exports', js)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}
for (const platform of ['native', 'web']) test(`${platform} connector blocks answers and acknowledgement until attachments upload`, async t => {
  const events = [], session = { revision: 0 };
  let offline = true;
  const auth = {
    loadCredentials: async () => ({ userId: 'owner' }), getApiBaseUrl: () => 'https://api',
    login: async () => ({ token: 'token', expires_in: 3600 }),
    getSession: async () => ({ token: 'token', user: { id: 'owner' } }),
  };
  const { LaravelConnector } = load(platform === 'native' ? '../db/connector.ts' : '../../web/db/connector.ts', {
    '@/lib/intake/sync-operations': syncOperations,
    './auth': auth, '@/lib/account-session': { accountSession: session },
    '@/lib/intake/attachments/sync': { uploadIntakeAttachments: async (ops, owner, token, check) => {
      assert.equal(owner, 'owner'); assert.equal(token, 'token'); assert.equal(ops, operations);
      check(); events.push('files'); if (offline) throw new Error('offline');
    } },
  });
  t.mock.method(globalThis, 'fetch', async () => { events.push('answers'); return new Response('{}'); });
  const previousWindow = globalThis.window;
  globalThis.window = { location: { origin: 'https://app' } };
  t.after(() => { globalThis.window = previousWindow; });
  const db = { getNextCrudTransaction: async () => ({ crud: operations, complete: async () => { events.push('ack'); } }) };
  const connector = new LaravelConnector('owner');
  await assert.rejects(connector.uploadData(db), /offline/);
  assert.deepEqual(events, ['files']);
  offline = false;
  await connector.uploadData(db);
  assert.deepEqual(events, ['files', 'files', 'answers', 'ack']);
});

test('native storage copies picker bytes to documents and recovers the pending record after restart', async () => {
  const metadata = new Map(), files = new Map([['cache/photo.jpg', 'photo bytes']]);
  const modules = {
    'expo-file-system': {
      Paths: { document: 'documents' },
      Directory: class { constructor(...parts) { this.uri = parts.join('/'); } create() {} },
      File: class {
        constructor(...parts) { this.uri = parts.map(p => p.uri ?? p).join('/'); }
        get size() { return files.get(this.uri).length; }
        copy(destination) { files.set(destination.uri, files.get(this.uri)); }
      },
    },
    '@react-native-async-storage/async-storage': { default: { getItem: async key => metadata.get(key), setItem: async (key, value) => { metadata.set(key, value); } } },
    './events': { attachmentsChanged() {} },
  };
  const before = load('../lib/intake/attachments/storage.ts', modules);
  await before.saveAttachment({ id, userId: 'owner', uploaded: false }, { uri: 'cache/photo.jpg' });
  files.delete('cache/photo.jpg');
  const after = load('../lib/intake/attachments/storage.ts', modules);
  const record = await after.attachmentStorage.get('owner', id);
  assert.equal(files.get(record.uri), 'photo bytes');
  assert.equal(record.uploaded, false);
  assert.equal(await after.attachmentStorage.get('other', id), null);
  await after.attachmentStorage.put({ ...record, uploaded: true });
  assert.equal((await after.attachmentStorage.get('owner', id)).uploaded, true);
});

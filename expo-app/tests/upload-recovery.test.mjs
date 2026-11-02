import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as requests from '../lib/sync-request.ts';
import * as operations from '../lib/intake/sync-operations.ts';
import * as uploads from '../lib/intake/attachments/upload.ts';
import { AccountSessionQueue } from '../lib/account-session.ts';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import { refreshWebApp } from '../../web/lib/web-release.ts';
import { preserveIntakeForRefresh } from '../lib/intake/refresh.ts';
import { saveAnswer } from '../lib/intake/persistence.ts';
import { AbortOperation, ConnectionManager } from '@powersync/common';

const settle = async () => { for (let i = 0; i < 80; i++) await Promise.resolve(); };
const deferred = () => {
  let resolve;
  return { promise: new Promise(done => { resolve = done; }), resolve: value => resolve(value) };
};
function load(path, modules, globals = {}) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  new Function('require', 'exports', ...Object.keys(globals), code)(key => {
    assert.ok(key in modules, key); return modules[key];
  }, exports, ...Object.values(globals));
  return exports;
}

function setup() {
  const accountSession = new AccountSessionQueue(), events = [];
  const reporting = load('../lib/sync-errors.ts', {
    '@powersync/common': { AbortOperation },
    '@/lib/error-tracking': { captureException: (error, context) => events.push({ message: error.message, ...context }) },
    './sync-request': requests,
  });
  const common = { '@/lib/sync-request': requests, '@/lib/sync-errors': reporting, '@/lib/account-session': { accountSession } };
  const { retrySyncUploads } = load('../db/retry-sync.ts', {
    '../lib/account-session': { accountSession }, '../lib/sync-request': requests, '../lib/sync-errors': reporting,
  });
  return { accountSession, events, reporting, common, retrySyncUploads };
}

test('timeouts abort the transport and reject late response-body writes', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const body = deferred();
  let signal, saved = false;
  const result = requests.syncRequest(100, async (s, check) => {
    signal = s; await body.promise; check(); saved = true;
  });
  const rejected = assert.rejects(result, error => error.kind === 'timeout');
  t.mock.timers.tick(100); await rejected;
  assert.equal(signal.aborted, true);
  body.resolve(); await settle(); assert.equal(saved, false);
});

test('retry cancels a hung upload before reconnecting; a late response cannot acknowledge it', async t => {
  const app = setup(), stalled = deferred();
  let acknowledged = 0, uploadSignal, running;
  const { LaravelConnector } = load('../../web/db/connector.ts', {
    ...app.common, './auth': { getSession: async () => ({ user: { id: 'owner' }, token: 'token' }) },
    '@/lib/intake/attachments/sync': { uploadIntakeAttachments: async () => {} },
    '@/lib/intake/sync-operations': operations,
  }, { window: { location: { origin: 'https://app.test' } } });
  const connector = new LaravelConnector('owner');
  t.mock.method(globalThis, 'fetch', async (_url, options) => { uploadSignal = options.signal; return stalled.promise; });
  const db = {
    getNextCrudTransaction: async () => ({ crud: [], complete: async () => acknowledged++ }),
    disconnect: async () => { await running.catch(() => {}); },
    connect: async () => {},
    disconnectAndClear: () => assert.fail('must not clear local data'),
  };
  running = connector.uploadData(db);
  const rejected = assert.rejects(running, error => error.kind === 'cancelled');
  await settle(); assert.ok(uploadSignal);
  await app.retrySyncUploads(db, connector); await rejected;
  assert.equal(uploadSignal.aborted, true);
  stalled.resolve(new Response('{}')); await settle(); assert.equal(acknowledged, 0);
  t.mock.method(globalThis, 'fetch', async () => new Response('{}'));
  await connector.uploadData(db); assert.equal(acknowledged, 1);
  assert.equal(app.events.length, 0, 'manual cancellation is not a failure report');
});

test('simultaneous retry clicks reconnect once and account changes prevent an old reconnection', async () => {
  const app = setup(), disconnected = deferred();
  let disconnects = 0, connects = 0;
  const db = { disconnect: async () => { disconnects++; await disconnected.promise; }, connect: async () => connects++ };
  const connector = { fetchCredentials: async () => null, uploadData: async () => {} };
  const one = app.retrySyncUploads(db, connector), two = app.retrySyncUploads(db, connector);
  assert.equal(one, two);
  const rejected = assert.rejects(one, /sessie is gewijzigd/);
  await settle();
  const logout = app.accountSession.transition(async () => {});
  disconnected.resolve(); await rejected; await logout;
  assert.equal(disconnects, 1); assert.equal(connects, 0);
});

for (const stage of ['disconnect', 'connect']) test(`a stalled ${stage} cannot hold the account queue after retry times out`, async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const app = setup(), stalled = deferred();
  let started = false, connects = 0, stops = 0;
  let lateConnector;
  const manager = new ConnectionManager({
    logger: { debug() {}, warn() {} },
    createSyncImplementation: async connector => {
      lateConnector = connector;
      return { sync: {
        waitForReady: async () => {},
        connect: async () => { started = true; connects++; if (stage === 'connect') await stalled.promise; },
        disconnect: async () => { stops++; }, dispose: async () => {},
      }, onDispose() {} };
    },
  });
  const db = {
    disconnect: async () => {
      if (stage === 'disconnect') { started = true; await stalled.promise; }
      await manager.disconnect();
    },
    connect: connector => manager.connect(connector, {}),
  };
  const connector = { fetchCredentials: async () => ({ token: 'token', endpoint: 'sync' }), uploadData: async () => assert.fail('stale upload') };
  const result = app.retrySyncUploads(db, connector);
  const rejected = assert.rejects(result, error => error.kind === 'timeout');
  await settle(); assert.equal(started, true);
  t.mock.timers.tick(30_000); await rejected; await settle();
  if (stage === 'connect') {
    assert.equal(stops, 1, 'cancel the SDK connection when the retry deadline expires');
    await assert.rejects(lateConnector.fetchCredentials(), error => error.kind === 'cancelled' || error.kind === 'timeout');
    await assert.rejects(async () => lateConnector.uploadData(db), error => error.kind === 'cancelled' || error.kind === 'timeout');
  }
  let accountActionCompleted = false;
  const accountAction = app.accountSession.transition(async () => { accountActionCompleted = true; });
  await settle();
  assert.equal(accountActionCompleted, true, 'a timeout must release the account queue before the stalled SDK settles');
  await accountAction;
  stalled.resolve(); await settle();
  assert.equal(connects, stage === 'connect' ? 1 : 0, 'a late disconnect must never reconnect after timeout');
  assert.equal(app.events.length, 1);
  assert.equal(app.events[0].tags.operation, 'sync.retry');
});

test('a fresh retry can connect after the previous SDK connect times out', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const app = setup(), stalled = deferred();
  let connects = 0;
  const manager = new ConnectionManager({
    logger: { debug() {}, warn() {} },
    createSyncImplementation: async () => ({ sync: {
      waitForReady: async () => {},
      connect: async () => { if (++connects === 1) await stalled.promise; },
      disconnect: async () => stalled.resolve(), dispose: async () => {},
    }, onDispose() {} }),
  });
  const db = { disconnect: () => manager.disconnect(), connect: connector => manager.connect(connector, {}) };
  const connector = { fetchCredentials: async () => null, uploadData: async () => {} };
  const result = app.retrySyncUploads(db, connector);
  const rejected = assert.rejects(result, error => error.kind === 'timeout');
  await settle(); t.mock.timers.tick(30_000); await rejected; await settle();
  await app.retrySyncUploads(db, connector);
  assert.equal(connects, 2);
});

test('file response timeout retains original bytes; retry reuses the attachment ID before acknowledging answers', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const app = setup(), body = deferred();
  const id = '11111111-1111-4111-8111-111111111111';
  let record = { id, userId: 'owner', bookingId: 'booking', horseId: 'horse', section: 'photos', field: 'right',
    name: 'private-name.jpg', mime: 'image/jpeg', blob: new Blob(['original image bytes']), uploaded: false };
  const storage = { get: async () => record, put: async value => { record = value; } };
  const attachmentSync = load('../lib/intake/attachments/sync.ts', {
    ...app.common, '@/db/auth': { getApiBaseUrl: () => '' }, './storage': { attachmentStorage: storage }, './upload': uploads,
  });
  const batches = [{ table: 'intake_answers', opData: { value: JSON.stringify([`attachment:${id}:private-name.jpg`]) } }];
  let signal;
  t.mock.method(globalThis, 'fetch', async (_url, options) => {
    signal = options.signal; return { ok: true, json: () => body.promise };
  });
  const first = attachmentSync.uploadIntakeAttachments(batches, 'owner', 'token', () => {});
  const rejected = assert.rejects(first, error => error.kind === 'timeout');
  await settle(); t.mock.timers.tick(120_000); await rejected;
  assert.equal(signal.aborted, true); assert.equal(record.uploaded, false);
  assert.match(record.error, /duurt te lang/);
  assert.equal(await record.blob.text(), 'original image bytes');
  body.resolve({ value: `attachment:${id}:private-name.jpg` }); await settle();
  assert.equal(record.uploaded, false);
  assert.equal(app.events[0].tags.operation, 'intake.attachment.upload');
  const sent = [];
  t.mock.method(globalThis, 'fetch', async (_url, options) => {
    sent.push(options.body.get('attachment_id'));
    assert.equal(await options.body.get('file').text(), 'original image bytes');
    return Response.json({ value: `attachment:${id}:private-name.jpg` });
  });
  await attachmentSync.uploadIntakeAttachments(batches, 'owner', 'token', () => {});
  assert.deepEqual(sent, [id]); assert.equal(record.uploaded, true); assert.equal(record.error, undefined);
});

test('reports include operation and HTTP status, suppress repeats, and never include raw payloads', () => {
  const { reporting, events } = setup();
  for (let i = 0; i < 10; i++) reporting.reportSyncFailure(requests.syncHttpError(422), 'intake.attachment.upload');
  const privateError = new Error('private-name.jpg horse-name private@example.test Bearer secret response-body');
  reporting.reportSyncFailure(privateError, 'sync.upload');
  reporting.reportSyncFailure(privateError, 'sync.download');
  reporting.reportSyncFailure(new requests.SyncRequestError('cancel', 'cancelled'), 'sync.upload');
  reporting.reportSyncFailure(new requests.SyncRequestError('pending', 'stalled'), 'intake.attachment.pending');
  assert.equal(events.length, 3);
  assert.equal(events[0].tags.http_status, '422');
  assert.equal(events[2].tags.failure_kind, 'stalled');
  for (const secret of ['private', 'horse-name', 'secret', 'response-body']) assert.ok(!JSON.stringify(events).includes(secret));
});

test('SDK cancellation does not consume the reporting limit for genuine HTTP failures', () => {
  const { reporting, events } = setup();
  reporting.reportSyncFailure(new AbortOperation('Connection request aborted'), 'sync.download');
  assert.equal(events.length, 0);
  for (const status of [503, 503, 401]) {
    const error = new Error('Private upstream response body');
    error.status = status;
    reporting.reportSyncFailure(error, 'sync.download');
  }
  assert.deepEqual(events.map(event => event.tags), [
    { operation: 'sync.download', failure_kind: 'http', http_status: '503' },
    { operation: 'sync.download', failure_kind: 'http', http_status: '401' },
  ]);
  assert.ok(!JSON.stringify(events).includes('Private'));
});

test('invalid SDK status values are never included in telemetry', () => {
  for (const status of ['503', 'private@example.test', NaN, Infinity, 200, 700, 503.5]) {
    const { reporting, events } = setup();
    const error = new Error('Private error'); error.status = status;
    reporting.reportSyncFailure(error, 'sync.download');
    assert.equal(events[0].tags.failure_kind, 'unknown');
    assert.equal(events[0].tags.http_status, undefined);
  }
});

test('pending photo reference survives the update, then retry uploads its bytes before syncing the answer', async t => {
  const directory = await mkdtemp(path.join(tmpdir(), 'equi-upload-update-'));
  const file = path.join(directory, 'answers.db');
  let db = new DatabaseSync(file);
  t.after(async () => { db.close(); await rm(directory, { recursive: true, force: true }); });
  db.exec('CREATE TABLE intake_answers (id TEXT PRIMARY KEY,response_id TEXT,section_id TEXT,field_id TEXT,value TEXT,created_at TEXT,updated_at TEXT)');
  const adapter = {
    getOptional: async (sql, params) => db.prepare(sql).get(...params) ?? null,
    execute: async (sql, params) => db.prepare(sql).run(...params),
    writeTransaction: async callback => {
      db.exec('BEGIN');
      try { const result = await callback(adapter); db.exec('COMMIT'); return result; }
      catch (error) { db.exec('ROLLBACK'); throw error; }
    },
  };
  const id = '11111111-1111-4111-8111-111111111111', reference = `attachment:${id}:horse.jpg`;
  // Attachment storage is independent of the SQLite answer queue and survives module reloads.
  let saved = { id, userId: 'owner', bookingId: 'booking', horseId: 'horse', section: 'photos', field: 'right',
    name: 'horse.jpg', mime: 'image/jpeg', blob: new Blob(['original bytes']), uploaded: false };
  await refreshWebApp({ verify: async () => 'new-build', prepare: () => preserveIntakeForRefresh({
    drain: async () => {}, checkSession() {}, answersToSave: () => ({ photos: { right: [reference] } }),
    ensureBooking: async () => 'booking', save: (...args) => saveAnswer(adapter, ...args, () => crypto.randomUUID()),
  }), reload: () => { db.close(); db = new DatabaseSync(file); } });
  const app = setup(), events = [];
  const attachmentSync = load('../lib/intake/attachments/sync.ts', {
    ...app.common, '@/db/auth': { getApiBaseUrl: () => '' }, './upload': uploads,
    './storage': { attachmentStorage: { get: async () => saved, put: async record => { saved = record; } } },
  });
  const { LaravelConnector } = load('../../web/db/connector.ts', {
    ...app.common, './auth': { getSession: async () => ({ user: { id: 'owner' }, token: 'token' }) },
    '@/lib/intake/attachments/sync': attachmentSync, '@/lib/intake/sync-operations': operations,
  }, { window: { location: { origin: 'https://app.test' } } });
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    if (url.endsWith('/attachments')) {
      assert.equal(options.body.get('attachment_id'), id);
      assert.equal(await options.body.get('file').text(), 'original bytes');
      events.push('file'); return Response.json({ value: reference });
    }
    assert.equal(saved.uploaded, true);
    assert.equal(JSON.parse(JSON.parse(options.body).operations[0].data.value)[0], reference);
    events.push('answer'); return Response.json({});
  });
  const syncDb = {
    getNextCrudTransaction: async () => ({ crud: db.prepare('SELECT * FROM intake_answers').all().map(row => ({ table: 'intake_answers', op: 'PUT', id: row.id, opData: row })), complete: async () => events.push('ack') }),
    disconnect: async () => events.push('disconnect'),
    connect: async connector => { await connector.fetchCredentials(); await connector.uploadData(syncDb); },
    disconnectAndClear: () => assert.fail('pending answers must survive'),
  };
  await app.retrySyncUploads(syncDb, new LaravelConnector('owner'));
  assert.deepEqual(events, ['disconnect', 'file', 'answer', 'ack']);
  assert.equal(saved.uploaded, true);
});

test('retry button remains available while pending and success appears only after storage confirms upload', async () => {
  const app = setup();
  let uploaded = false, retries = 0;
  const states = [], effects = [];
  let cursor = 0;
  const jsx = (type, props) => ({ type, props });
  const { AttachmentField } = load('../components/intake/AttachmentField.tsx', {
    ...app.common, 'react/jsx-runtime': { jsx, jsxs: jsx },
    react: { useRef: value => ({ current: value }), useState: value => {
      const index = cursor++; if (!(index in states)) states[index] = value;
      return [states[index], value => { states[index] = value; }];
    }, useEffect: callback => { effects.push(callback); } },
    'react-native': Object.fromEntries(['View', 'Text', 'Pressable', 'ActivityIndicator'].map(key => [key, key])),
    'lucide-react-native': {}, 'expo-document-picker': {},
    '@/lib/intake/attachments/image-picker': {}, '@/lib/intake/attachments/photo-dimensions': {},
    '@/lib/intake/attachments/storage': { attachmentStorage: { get: async () => ({ uploaded }) } },
    '@/lib/intake/attachments/events': { onAttachmentsChanged: () => () => {} },
    '@/db/provider': { useDb: () => ({ currentUserId: 'owner', retryUploads: async () => retries++, retryingUploads: false, syncStatus: 'error' }) },
    '@/lib/intake/store': { useIntake: () => ({ state: {}, ensureBooking() {}, horseId: 'horse' }) },
    '@/lib/refresh-safety': { registerRefreshProtection: () => () => {}, refreshProtectionChanged() {} },
    './AttachmentPhoto': { Photo: 'Photo' },
  });
  const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
  const root = AttachmentField({ value: ['attachment:id:photo.jpg'], onChange() {}, section: 'photos', field: 'right', photo: true, max: 1 });
  const status = flatten(root).find(node => typeof node.type === 'function' && node.type.name === 'UploadStatus');
  states.length = 0; cursor = 0; effects.length = 0;
  status.type(status.props);
  const dispose = effects.map(effect => effect()).filter(value => typeof value === 'function');
  await settle(); cursor = 0;
  let tree = status.type(status.props);
  const button = flatten(tree).find(node => node.type === 'Pressable');
  assert.equal(button.props.children.props.children, 'Upload opnieuw proberen');
  button.props.onPress(); await settle(); assert.equal(retries, 1);
  assert.ok(!JSON.stringify(tree).includes('Geüpload'));
  states[0] = { uploaded: true }; cursor = 0;
  tree = status.type(status.props);
  assert.equal(tree.props.children, 'Geüpload');
  dispose.forEach(fn => fn());
});

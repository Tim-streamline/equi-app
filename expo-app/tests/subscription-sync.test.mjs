import * as syncOperations from '../lib/intake/sync-operations.ts';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { AccountSessionQueue } from '../lib/account-session.ts';

const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
};
function load(path, modules, globals = {}) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', ...Object.keys(globals), code)(name => {
    assert.ok(name in modules, name); return modules[name];
  }, exports, ...Object.values(globals));
  return exports;
}
function setup(now = 3000) {
  const queue = new AccountSessionQueue(), checkpoint = deferred(), events = [];
  let expired, predicate, syncConnector;
  const timers = new Map();
  let timerId = 0;
  const { refreshSubscriptionConnection } = load('../db/refresh-subscription.ts', { '../lib/account-session': { accountSession: queue } }, {
    setTimeout: (fn, delay) => { if (delay === 30_000) expired = fn; const id = ++timerId; timers.set(id, { fn, delay }); return id; },
    clearTimeout: id => timers.delete(id),
    Date: class extends Date { static now() { return now; } },
  });
  const db = {
    currentStatus: { connected: true, hasSynced: true, lastSyncedAt: new Date(1000) },
    disconnect: async () => { events.push('disconnect'); },
    connect: async connector => { syncConnector = connector; events.push((await connector.fetchCredentials()).token); },
    waitForStatus: async (check, signal) => {
      predicate = check;
      signal.addEventListener('abort', checkpoint.resolve, { once: true });
      await checkpoint.promise;
    },
    disconnectAndClear: () => assert.fail('must preserve local data and queued writes'),
  };
  const connector = { fetchCredentials: async () => ({ token: 'later', endpoint: 'sync' }), uploadData: async () => events.push('upload') };
  const signal = new AbortController();
  return { queue, checkpoint, events, db, connector, signal, refresh: fetch => refreshSubscriptionConnection(db, connector, fetch, signal.signal), refreshSubscriptionConnection,
    get predicate() { return predicate; }, get syncConnector() { return syncConnector; }, expire: () => expired(),
    get delays() { return [...timers.values()].map(timer => timer.delay); },
    advance: delay => { now += delay; for (const { fn, delay: scheduled } of [...timers.values()]) if (scheduled <= delay) fn(); },
  };
}
const settle = () => new Promise(resolve => setImmediate(resolve));

for (const platform of ['native', 'web']) test(`${platform} activation uses fresh credentials and waits beyond the old sync checkpoint`, async () => {
  const app = setup(); let mints = 0;
  const fresh = { token: 'subscribed:1', endpoint: 'https://sync', expires_in: 3600, user: { id: 'owner' } };
  const modules = platform === 'web' ? {
    './auth': { getSession: async (_signal, force) => { if (force) mints++; return { ...fresh, token: force || mints ? fresh.token : 'subscribed:0' }; } },
  } : {
    '@/lib/account-session': { accountSession: app.queue },
    './auth': { loadCredentials: async () => ({ userId: 'owner', email: 'test', password: 'secret' }), getApiBaseUrl: () => 'https://api', login: async () => { mints++; return fresh; } },
  };
  modules['@/lib/intake/sync-operations'] = syncOperations;
  modules['@/lib/account-session'] = { accountSession: app.queue };
  modules['@/lib/intake/attachments/sync'] = { uploadIntakeAttachments: async () => {} };
  const { LaravelConnector } = load(platform === 'web' ? '../../web/db/connector.ts' : '../db/connector.ts', modules, { window: { location: { origin: 'https://app' } }, console: { log() {}, warn() {} } });
  const connector = new LaravelConnector('owner');
  let done = false;
  const result = app.refreshSubscriptionConnection(app.db, connector, signal => connector.fetchCredentials(signal, true), app.signal.signal).then(() => { done = true; });
  await settle();
  assert.deepEqual(app.events, ['disconnect', 'subscribed:1']); assert.equal(mints, 1);
  assert.equal(app.predicate(app.db.currentStatus), false, 'old hasSynced must not release the gate');
  assert.equal(app.predicate({ connected: false, lastSyncedAt: new Date(2000) }), false);
  assert.equal(app.predicate({ connected: true, lastSyncedAt: new Date(2000) }), true);
  assert.equal(done, false);
  app.checkpoint.resolve(); await result; assert.equal(done, true);
});

test('a failed refresh leaves the current connection and offline queue intact; retry succeeds', async () => {
  const app = setup();
  await assert.rejects(app.refresh(async () => { throw new Error('Offline'); }), /Offline/);
  assert.deepEqual(app.events, []);
  const retry = app.refresh(async () => ({ token: 'fresh', endpoint: 'sync' }));
  await settle(); app.checkpoint.resolve(); await retry;
  assert.deepEqual(app.events, ['disconnect', 'fresh']);
});

test('logout invalidates a late token without reconnecting the old account', async () => {
  const app = setup(), credentials = deferred();
  const refresh = app.refresh(() => credentials.promise);
  await app.queue.transition(async () => app.events.push('logout'));
  credentials.resolve({ token: 'old-account', endpoint: 'sync' });
  await assert.rejects(refresh, /sessie is gewijzigd/);
  assert.deepEqual(app.events, ['logout']);
});

test('waiting for sync does not block logout, and old connector work is rejected', async () => {
  const app = setup();
  const refresh = app.refresh(async () => ({ token: 'fresh', endpoint: 'sync' }));
  await settle();
  await app.queue.transition(async () => app.events.push('logout'));
  app.checkpoint.resolve(); await assert.rejects(refresh, /sessie is gewijzigd/);
  await assert.rejects(app.syncConnector.fetchCredentials(), /sessie is gewijzigd/);
  assert.deepEqual(app.events, ['disconnect', 'fresh', 'logout']);
});

test('a stalled token request or checkpoint times out instead of reporting activation complete', async () => {
  for (const step of ['token', 'checkpoint']) {
    const app = setup();
    const result = app.refresh(step === 'token' ? () => new Promise(() => {}) : async () => ({ token: 'fresh', endpoint: 'sync' }));
    await settle(); app.expire();
    await assert.rejects(result, /Probeer opnieuw/);
  }
});

test('activation within the previous checkpoint second succeeds on its first refresh', async () => {
  const app = setup(1500);
  let clock = 1500, complete = false;
  // Reproduce the installed core's whole-second checkpoint precision. Without
  // the boundary wait, the reconnect completes at 1000 and fails freshness.
  const connect = app.db.connect;
  app.db.connect = async connector => {
    await connect(connector);
    app.db.currentStatus = { connected: true, hasSynced: true, lastSyncedAt: new Date(Math.floor(clock / 1000) * 1000) };
  };
  app.db.waitForStatus = async (predicate, signal) => {
    if (predicate(app.db.currentStatus)) return;
    await new Promise(resolve => signal.addEventListener('abort', resolve, { once: true }));
  };
  const refresh = app.refresh(async () => ({ token: 'subscribed:1', endpoint: 'sync' })).then(() => { complete = true; });
  await settle();
  assert.deepEqual(app.events, ['disconnect']);
  assert.ok(app.delays.includes(500));
  clock = 2000; app.advance(500); await settle();
  assert.equal(complete, true, 'a completed first refresh must not need the retry button');
  await refresh;
  assert.deepEqual(app.events, ['disconnect', 'subscribed:1']);
  assert.deepEqual(app.delays, []);
});

test('logout during the timestamp boundary wait is immediate and prevents reconnection', async () => {
  const app = setup(1500);
  const refresh = app.refresh(async () => ({ token: 'old-account', endpoint: 'sync' }));
  await settle();
  await app.queue.transition(async () => app.events.push('logout'));
  app.advance(500);
  await assert.rejects(refresh, /sessie is gewijzigd/);
  assert.deepEqual(app.events, ['disconnect', 'logout']);
  assert.deepEqual(app.delays, []);
});

test('cancelling during the timestamp boundary wait clears the timer without reconnecting', async () => {
  const app = setup(1500);
  const refresh = app.refresh(async () => ({ token: 'fresh', endpoint: 'sync' }));
  await settle(); app.signal.abort();
  await assert.rejects(refresh, /Probeer opnieuw/);
  assert.deepEqual(app.events, ['disconnect']);
  assert.deepEqual(app.delays, []);
});

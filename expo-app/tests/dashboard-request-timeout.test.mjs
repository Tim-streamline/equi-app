import * as syncRequests from '../lib/sync-request.ts';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import { AccountSessionQueue } from '../lib/account-session.ts';

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}
const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };

// Execute the real request/auth/connector functions with only native dependencies mocked.
function harness(fetch, { legacy = null, reinstalled = false, saveFails = false } = {}) {
  const timers = new Map();
  let timerId = 0;
  // Secure storage holds the device token; AsyncStorage an older version's password and the install marker.
  const stored = {
    secure: legacy ? null : JSON.stringify({ email: 'old@example.test', deviceToken: '1|old-device', userId: 'old' }),
    legacy: legacy && JSON.stringify(legacy),
    installed: reinstalled ? null : '1',
  };
  const field = key => ({ 'equinova:auth:v1': 'legacy', 'equinova:installed': 'installed' })[key];
  const storage = {
    getItem: async (key) => stored[field(key)] ?? null,
    setItem: async (key, value) => { stored[field(key)] = value; },
    removeItem: async (key) => { stored[field(key)] = null; },
  };
  const secureStore = {
    AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY: 'afterFirstUnlockThisDevice',
    getItemAsync: async () => stored.secure,
    setItemAsync: async (_key, value) => { if (saveFails) throw new Error('Keystore unavailable'); stored.secure = value; },
    deleteItemAsync: async () => { stored.secure = null; },
  };
  const modules = {
    '@react-native-async-storage/async-storage': { default: storage },
    'expo-secure-store': secureStore,
    '@/lib/library-events': {},
    react: {}, 'react-native': { Platform: { OS: 'android', Version: 34 } }, 'expo-router': {},
    '@/lib/account-session': { accountSession: new AccountSessionQueue() },
    '@/db/provider': {}, '@/db/hooks': {}, '@/lib/horse-dashboard': {},
  };
  function load(path) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
    const exports = {};
    vm.runInNewContext(code, {
      exports, require: (name) => {
        if (name === '@/lib/sync-request') return syncRequests; if (name === '@/lib/sync-errors') return { reportSyncFailure() {} }; assert.ok(name in modules, `Unexpected dependency: ${name}`);
        return modules[name];
      },
      fetch, AbortController, process: { env: {} }, console: { log() {}, warn() {} },
      // The request deadline, or the shared password upgrade's own deadline.
      setTimeout: (fn, delay) => { assert.ok([15000, 20000].includes(delay), String(delay)); timers.set(++timerId, { fn, delay }); return timerId; },
      clearTimeout: (id) => timers.delete(id),
    });
    return exports;
  }
  const auth = load('../db/auth.ts');
  modules['./auth'] = modules['@/db/auth'] = auth;
  modules['@/lib/intake/attachments/sync'] = { uploadIntakeAttachments: async () => {} };
  // The connector prepares queued intake answers; use the real helper so the test covers it.
  modules['@/lib/intake/sync-operations'] = load('../lib/intake/sync-operations.ts');
  const connector = load('../db/connector.ts');
  modules['@/db/connector'] = connector;
  const { dashboardRequest } = load('../hooks/useHorseDashboard.ts');
  return {
    dashboardRequest, auth, connector, timers, stored,
    expire: () => {
      const deadlines = [...timers.values()].filter(timer => timer.delay === 15000);
      assert.equal(deadlines.length, 1, 'The deadline must already cover token acquisition');
      deadlines[0].fn();
    },
  };
}

test('stalled token acquisition releases logout cleanup and the next queued login', async () => {
  const pending = deferred();
  const calls = [];
  const app = harness((url, options) => { calls.push({ url, options }); return pending.promise; });
  const queue = new AccountSessionQueue();
  const events = [];
  const logout = queue.transition(async () => {
    try { await app.dashboardRequest('/api/notifications/push-token', { token: null }); }
    catch { events.push('unregister failed'); }
    await app.auth.clearCredentials();
    events.push('local logout');
  });
  const login = queue.transition(async () => { events.push('next login'); });
  await flush();
  app.expire();
  await Promise.all([logout, login]);
  assert.deepEqual(events, ['unregister failed', 'local logout', 'next login']);
  assert.equal(await app.auth.loadCredentials(), null);
  assert.equal(calls[0].options.signal.aborted, true);
  assert.equal(app.timers.size, 0);

  // A transport completing after cancellation must not send a stale unregister.
  pending.resolve({ ok: true, json: async () => ({ token: 'late-token', expires_in: 3600 }) });
  await flush();
  assert.equal(calls.length, 1);
});

test('a late failed login cannot clear credentials saved after logout timed out', async () => {
  const pending = deferred();
  const app = harness(() => pending.promise);
  const result = app.dashboardRequest('/api/notifications/push-token', { token: null });
  const rejected = assert.rejects(result, /duurde te lang/i);
  await flush(); app.expire(); await rejected;
  await app.auth.saveCredentials({ email: 'new@example.test', deviceToken: '2|new-device', userId: 'new', endpoint: 'https://sync.test' });
  pending.resolve({ ok: false, status: 401, text: async () => 'Unauthorized' });
  await flush();
  assert.equal((await app.auth.loadCredentials()).userId, 'new');
});

test('the same deadline covers reading the unregister response body', async () => {
  const body = deferred();
  const app = harness(async (url) => url.endsWith('/api/auth/token')
    ? { ok: true, json: async () => ({ token: 'token', expires_in: 3600 }) }
    : { ok: true, json: () => body.promise });
  const result = app.dashboardRequest('/api/notifications/push-token', { token: null });
  const rejected = assert.rejects(result, /duurde te lang/i);
  await flush(); app.expire(); await rejected;
  assert.equal(app.timers.size, 0);
});

test('successful unregistration authenticates and clears its deadline', async () => {
  const calls = [];
  const app = harness(async (url, options) => {
    calls.push({ url, options });
    return { ok: true, json: async () => url.endsWith('/api/auth/token')
      ? { token: 'token', expires_in: 3600 } : { saved: true } };
  });
  const result = await app.dashboardRequest('/api/notifications/push-token', { token: null });
  assert.equal(result.saved, true);
  assert.deepEqual(JSON.parse(calls[0].options.body), { device_token: '1|old-device', capabilities: ['protocol-doses'] });
  assert.equal(calls[1].options.headers.Authorization, 'Bearer token');
  assert.equal(calls[1].options.body, '{"token":null}');
  assert.equal(app.timers.size, 0);
});

test('a password stored by an older version is exchanged once for a device token and deleted', async () => {
  const calls = [];
  const app = harness(async (url, options) => {
    calls.push({ url, body: JSON.parse(options.body) });
    return { ok: true, json: async () => url.endsWith('/api/auth/login')
      ? { token: 'token', expires_in: 3600, endpoint: 'https://sync.test', device_token: '3|device', user: { id: 'old', email: 'old@example.test' } }
      : { token: 'token-2', expires_in: 3600, endpoint: 'https://sync.test' } };
  }, { legacy: { email: 'old@example.test', password: 'secret', userId: 'old', endpoint: 'https://sync.test' } });
  assert.equal(await app.connector.getOrMintToken(), 'token');
  assert.deepEqual(calls[0], { url: 'http://localhost/api/auth/login', body: { email: 'old@example.test', password: 'secret', device_name: 'android 34', capabilities: ['protocol-doses'] } });
  assert.equal(app.stored.legacy, null);
  assert.deepEqual(JSON.parse(app.stored.secure), { email: 'old@example.test', userId: 'old', endpoint: 'https://sync.test', deviceToken: '3|device' });
  assert.equal(await app.connector.getOrMintToken(undefined, true), 'token-2');
  assert.deepEqual(calls[1], { url: 'http://localhost/api/auth/token', body: { device_token: '3|device', capabilities: ['protocol-doses'] } });
});

test('an older stored password stays in use while the server cannot issue device tokens yet', async () => {
  const app = harness(async () => ({ ok: true, json: async () => ({ token: 'token', expires_in: 3600, endpoint: 'https://sync.test', user: { id: 'old', email: 'old@example.test' } }) }),
    { legacy: { email: 'old@example.test', password: 'secret', userId: 'old', endpoint: 'https://sync.test' } });
  assert.equal(await app.connector.getOrMintToken(), 'token');
  assert.equal(app.stored.secure, null);
  assert.equal(JSON.parse(app.stored.legacy).password, 'secret');
});

test('a revoked device token signs the app out', async () => {
  const app = harness(async () => ({ ok: false, status: 401, text: async () => 'Invalid credentials' }));
  assert.equal(await app.connector.getOrMintToken(), null);
  assert.equal(await app.auth.loadCredentials(), null);
});

test('concurrent callers share one password upgrade, so it leaves a single device token', async () => {
  const logins = [];
  const login = deferred();
  const app = harness(async (url) => {
    if (url.endsWith('/api/auth/login')) { logins.push(url); await login.promise; }
    return { ok: true, json: async () => ({ token: 'token', expires_in: 3600, endpoint: 'https://sync.test', device_token: `${logins.length}|device`, user: { id: 'old', email: 'old@example.test' } }) };
  }, { legacy: { email: 'old@example.test', password: 'secret', userId: 'old', endpoint: 'https://sync.test' } });
  const sync = new app.connector.LaravelConnector().fetchCredentials();
  const dashboard = app.connector.getOrMintToken();
  await flush();
  login.resolve();
  assert.equal((await sync).token, 'token');
  assert.equal(await dashboard, 'token');
  assert.equal(logins.length, 1);
  assert.equal(JSON.parse(app.stored.secure).deviceToken, '1|device');
  assert.equal(app.stored.legacy, null);
  assert.equal(app.timers.size, 0);
});

test('a reinstalled app does not sign back in with the previous installation\'s device token', async () => {
  const app = harness(async () => assert.fail('No request without credentials'), { reinstalled: true });
  assert.equal(await app.connector.getOrMintToken(), null);
  assert.equal(app.stored.secure, null);
  assert.equal(app.stored.installed, '1');
  const creds = { email: 'new@example.test', deviceToken: '2|new-device', userId: 'new', endpoint: 'https://sync.test' };
  await app.auth.saveCredentials(creds);
  assert.deepEqual({ ...await app.auth.loadCredentials() }, creds);
});

test('when the device token cannot be stored, sync still gets its token and the unkept device token is revoked', async () => {
  const calls = [];
  const app = harness(async (url, options) => {
    calls.push({ url, body: JSON.parse(options.body) });
    return { ok: true, json: async () => ({ token: `token-${calls.length}`, expires_in: 3600, endpoint: 'https://sync.test', device_token: `${calls.length}|device`, user: { id: 'old', email: 'old@example.test' } }) };
  }, { legacy: { email: 'old@example.test', password: 'secret', userId: 'old', endpoint: 'https://sync.test' }, saveFails: true });
  assert.equal(await app.connector.getOrMintToken(), 'token-1');
  await flush();
  assert.deepEqual(calls.map(call => call.url.replace('http://localhost', '')), ['/api/auth/login', '/api/auth/logout']);
  assert.deepEqual(calls[1].body, { device_token: '1|device' });
  assert.equal(JSON.parse(app.stored.legacy).password, 'secret');
});


test('registration completion requests the same signed protocol capability as login and refresh', async () => {
  const calls = [];
  const app = harness(async (url, options) => {
    calls.push({ url, body: JSON.parse(options.body) });
    return { ok: true, json: async () => ({ token: 'jwt', expires_in: 3600 }) };
  });
  await app.auth.completeRegistration({ registration_token: 'proof' });
  assert.deepEqual(calls[0].body.capabilities, ['protocol-doses']);
  assert.equal(calls[0].body.registration_token, 'proof');
});

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
function harness(fetch) {
  const timers = new Map();
  let timerId = 0;
  let credentials = JSON.stringify({ email: 'old@example.test', password: 'test', userId: 'old' });
  const storage = {
    getItem: async () => credentials,
    setItem: async (_key, value) => { credentials = value; },
    removeItem: async () => { credentials = null; },
  };
  const modules = {
    '@react-native-async-storage/async-storage': { default: storage },
    react: {}, 'react-native': {}, 'expo-router': {},
    '@/db/provider': {}, '@/db/hooks': {}, '@/lib/horse-dashboard': {},
  };
  function load(path) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
    const exports = {};
    vm.runInNewContext(code, {
      exports, require: (name) => {
        assert.ok(name in modules, `Unexpected dependency: ${name}`);
        return modules[name];
      },
      fetch, AbortController, process: { env: {} }, console: { log() {}, warn() {} },
      setTimeout: (fn, delay) => { assert.equal(delay, 15000); timers.set(++timerId, fn); return timerId; },
      clearTimeout: (id) => timers.delete(id),
    });
    return exports;
  }
  const auth = load('../db/auth.ts');
  modules['./auth'] = modules['@/db/auth'] = auth;
  const connector = load('../db/connector.ts');
  modules['@/db/connector'] = connector;
  const { dashboardRequest } = load('../hooks/useHorseDashboard.ts');
  return {
    dashboardRequest, auth, connector, timers,
    expire: () => {
      assert.equal(timers.size, 1, 'The deadline must already cover token acquisition');
      for (const callback of [...timers.values()]) callback();
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
  await app.auth.saveCredentials({ email: 'new@example.test', password: 'new', userId: 'new' });
  pending.resolve({ ok: false, status: 401, text: async () => 'Unauthorized' });
  await flush();
  assert.equal((await app.auth.loadCredentials()).userId, 'new');
});

test('the same deadline covers reading the unregister response body', async () => {
  const body = deferred();
  const app = harness(async (url) => url.endsWith('/api/auth/login')
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
    return { ok: true, json: async () => url.endsWith('/api/auth/login')
      ? { token: 'token', expires_in: 3600 } : { saved: true } };
  });
  const result = await app.dashboardRequest('/api/notifications/push-token', { token: null });
  assert.equal(result.saved, true);
  assert.equal(calls[1].options.headers.Authorization, 'Bearer token');
  assert.equal(calls[1].options.body, '{"token":null}');
  assert.equal(app.timers.size, 0);
});

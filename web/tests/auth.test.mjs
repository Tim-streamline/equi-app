import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { forgetSession, getSession, login, logout } from '../db/auth.ts';
const originalFetch = globalThis.fetch;
afterEach(() => { globalThis.fetch = originalFetch; forgetSession(); });
const response = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const session = id => ({ user: { id, name: 'Test', email: 'test@example.test' }, token: `jwt-${id}`, endpoint: 'http://sync', expires_in: 3600 });

test('login uses CSRF and HttpOnly session transport; renewal never resends the password', async () => {
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push({ url, options });
    return response(url.endsWith('/csrf') ? { token: 'current-csrf' } : session('first'));
  };
  await login('test@example.test', 'secret');
  await getSession(undefined, true);
  assert.deepEqual(requests.map(request => request.url), ['/web-session/csrf', '/web-session/login', '/web-session/csrf', '/web-session/token']);
  assert.equal(requests[1].options.headers['X-CSRF-TOKEN'], 'current-csrf');
  assert.equal(requests[1].options.credentials, 'same-origin');
  assert.equal(requests[3].options.body, '{}');
  assert.equal((await getSession()).user.id, 'first');
  assert.equal(requests.length, 4, 'valid tokens are cached in memory');
});

test('late token renewal cannot restore a cleared session', async () => {
  let finish;
  globalThis.fetch = async url => url.endsWith('/csrf') ? response({ token: 'csrf' }) : new Promise(resolve => { finish = resolve; });
  const renewing = getSession();
  while (!finish) await new Promise(resolve => setImmediate(resolve));
  forgetSession();
  finish(response(session('old')));
  await assert.rejects(renewing, /sessie is gewijzigd/);
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : {}, url.endsWith('/csrf') ? 200 : 401);
  assert.equal(await getSession(), null);
});

test('renewal refuses a different account from another browser tab', async () => {
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('first'));
  await login('first@example.test', 'secret');
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('second'));
  await assert.rejects(getSession(undefined, true), /ander tabblad/);
});

test('failed CSRF fetch never submits credentials and failed logout remains retryable', async () => {
  let requests = 0;
  globalThis.fetch = async () => { requests++; return response({}, 503); };
  await assert.rejects(login('test@example.test', 'secret'), /Verbinding/);
  assert.equal(requests, 1);
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('first'));
  await login('test@example.test', 'secret');
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : {}, url.endsWith('/csrf') ? 200 : 503);
  await assert.rejects(logout(), /Uitloggen/);
  assert.equal((await getSession()).user.id, 'first');
});

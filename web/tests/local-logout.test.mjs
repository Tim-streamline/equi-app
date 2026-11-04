import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as syncRequests from '../../expo-app/lib/sync-request.ts';
import { AccountSessionQueue } from '../../expo-app/lib/account-session.ts';

const source = ts.transpileModule(readFileSync(new URL('../db/auth.ts', import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const response = (body, status = 200) => new Response(JSON.stringify(body), { status });
const token = id => ({ user: { id }, token: `token-${id}`, expires_in: 3600, endpoint: '/powersync', logout_id: `login-${id}` });
const settle = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
function browser() {
  const values = new Map(); let tail = Promise.resolve();
  return {
    storage: { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) },
    locks: { request: (_key, work) => { const next = tail.then(work); tail = next.catch(() => {}); return next; } },
  };
}
function tab(shared = browser()) {
  const calls = [];
  const app = { fetch: async (url, options) => {
    if (url.endsWith('/csrf')) return response({ token: 'csrf' });
    if (url.endsWith('/login')) return response(token(JSON.parse(options.body).email));
    if (url.endsWith('/complete')) return response(token('registered'));
    return response(url.endsWith('/logout') ? { ok: true } : token('owner'));
  } };
  const exports = {};
  new Function('require', 'exports', 'fetch', 'localStorage', 'navigator', 'crypto', 'window', source)(
    () => syncRequests, exports,
    (url, options) => { calls.push({ url, options }); return app.fetch(url, options); },
    shared.storage, { locks: shared.locks }, crypto, undefined,
  );
  return { ...exports, app, calls, shared };
}

test('offline logout completes locally, survives a fresh tab and retries without restoring the cookie session', async () => {
  const first = tab();
  await first.login('owner', 'password');
  first.app.fetch = async () => { throw new Error('offline'); };
  first.logout();
  assert.equal(first.isLocallySignedOut(), true);
  assert.equal(await first.getSession(), null);
  assert.equal(await first.retryPendingLogout(), false);
  const reopened = tab(first.shared);
  assert.equal(await reopened.getSession(undefined, true), null);
  assert.equal(reopened.calls.length, 0, 'a reload must not restore the still-valid cookie');
  assert.equal(await reopened.retryPendingLogout(), true);
  const request = reopened.calls.find(call => call.url.endsWith('/logout'));
  assert.deepEqual(JSON.parse(request.options.body), { logout_id: 'login-owner' });
  assert.equal(await reopened.getSession(), null, 'successful backend cleanup also leaves the user signed out');
  const count = reopened.calls.length;
  assert.equal(await reopened.retryPendingLogout(), false);
  assert.equal(reopened.calls.length, count);
  await reopened.login('new-owner', 'password');
  assert.equal(reopened.isLocallySignedOut(), false);
  assert.equal((await reopened.getSession()).user.id, 'new-owner');
});

test('a stalled cleanup times out, and a late CSRF response cannot send logout afterwards', async t => {
  const first = tab(); await first.login('owner', 'password');
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const csrf = deferred();
  first.app.fetch = () => csrf.promise;
  first.logout();
  const retry = first.retryPendingLogout();
  for (let i = 0; i < 20; i++) await Promise.resolve();
  assert.equal(await first.getSession(), null);
  t.mock.timers.tick(20_000);
  assert.equal(await retry, false);
  csrf.resolve(response({ token: 'late-csrf' }));
  await settle();
  assert.equal(first.calls.filter(call => call.url.endsWith('/logout')).length, 0);
  assert.equal(first.isLocallySignedOut(), true);
});

test('a new login cancels an in-flight cleanup; its late response cannot sign out the new session', async () => {
  const first = tab(); await first.login('owner', 'password');
  const late = deferred(); const transport = first.app.fetch;
  first.app.fetch = (url, options) => url.endsWith('/logout') ? late.promise : transport(url, options);
  first.logout(); await settle();
  const oldRequest = first.calls.find(call => call.url.endsWith('/logout'));
  assert.ok(oldRequest);
  await first.login('new-owner', 'password');
  assert.equal(oldRequest.options.signal.aborted, true);
  late.resolve(response({ ok: true })); await settle();
  assert.equal(first.isLocallySignedOut(), false);
  assert.equal((await first.getSession()).user.id, 'new-owner');
  assert.equal(await first.retryPendingLogout(), false);
});

test('failed explicit login preserves sign-out; registration completion clears it only on success', async () => {
  const first = tab(); await first.login('owner', 'password');
  first.app.fetch = async () => { throw new Error('offline'); };
  first.logout(); await first.retryPendingLogout();
  first.app.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : {}, url.endsWith('/csrf') ? 200 : 401);
  await assert.rejects(first.login('owner', 'wrong'), /onjuist/);
  assert.equal(await first.getSession(), null);
  const completing = deferred();
  first.app.fetch = async url => url.endsWith('/csrf') ? response({ token: 'csrf' }) : completing.promise;
  const registration = first.completeRegistration({ registration_token: 'proof' });
  await settle();
  assert.equal(await first.getSession(), null, 'a background session check must not cancel explicit authentication');
  completing.resolve(response(token('registered'))); await registration;
  assert.equal((await first.getSession()).user.id, 'registered');
});

test('another tab cannot return cached tokens or accept a late renewal after local sign-out', async () => {
  const shared = browser(), first = tab(shared), second = tab(shared);
  await first.login('owner', 'password'); await second.getSession();
  const late = deferred();
  second.app.fetch = async url => url.endsWith('/csrf') ? response({ token: 'csrf' }) : late.promise;
  const renewing = second.getSession(undefined, true);
  const rejected = assert.rejects(renewing, /sessie/);
  await settle();
  first.app.fetch = async () => { throw new Error('offline'); };
  first.logout(); await first.retryPendingLogout();
  assert.equal(await second.getSession(), null);
  late.resolve(response(token('owner'))); await rejected;
  assert.equal(await second.getSession(), null);
});

test('the web provider clears its database without waiting for network or push cleanup', async () => {
  const first = tab(); await first.login('owner', 'password');
  first.app.fetch = async () => { throw new Error('offline'); };
  const session = new AccountSessionQueue(), events = [];
  const text = readFileSync(new URL('../db/provider.tsx', import.meta.url), 'utf8');
  const body = text.slice(text.indexOf('  const logout = () => {'), text.indexOf('\n  if (error)'));
  const logout = new Function('signOut', 'cancelSyncRequests', 'disableBrowserPush', 'channel', 'accountSession', 'open', `${body}; return logout;`)(
    first.logout, () => events.push('cancel'),
    (_, notifyBackend) => { assert.equal(notifyBackend, false); events.push('push'); return new Promise(() => {}); },
    { current: { postMessage: () => events.push('broadcast') } }, session,
    async next => { assert.equal(next, null); events.push('clear database'); },
  );
  await logout();
  assert.equal(first.isLocallySignedOut(), true);
  assert.equal(session.revision, 1);
  assert.deepEqual(events, ['cancel', 'push', 'broadcast', 'clear database']);
  await first.retryPendingLogout();
});

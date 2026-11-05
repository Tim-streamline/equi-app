import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { forgetSession, getSession, login, logout, requestPasswordReset, retryPendingLogout, LOCAL_SIGNOUT_KEY } from '../db/auth.ts';
const originalFetch = globalThis.fetch;
const originalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage');
beforeEach(() => {
  const values = new Map();
  Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: {
    getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key),
  } });
});
afterEach(async () => {
  await retryPendingLogout();
  globalThis.fetch = originalFetch; forgetSession();
  if (originalStorage) Object.defineProperty(globalThis, 'localStorage', originalStorage);
  else delete globalThis.localStorage;
});
const response = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const session = id => ({ user: { id, name: 'Test', email: 'test@example.test' }, token: `jwt-${id}`, endpoint: 'http://sync', expires_in: 3600, logout_id: `login-${id}` });

test('a hung shared token request times out, clears pending state and cannot overwrite the retry', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  let finish, signal;
  globalThis.fetch = async (url, options) => {
    signal = options.signal;
    if (url.endsWith('/csrf')) return response({ token: 'csrf' });
    return new Promise(resolve => { finish = resolve; });
  };
  const one = getSession(), two = getSession();
  const rejected = Promise.all([assert.rejects(one, error => error.kind === 'timeout'), assert.rejects(two, error => error.kind === 'timeout')]);
  for (let i = 0; i < 30; i++) await Promise.resolve();
  assert.ok(finish);
  t.mock.timers.tick(20_000); await rejected;
  assert.equal(signal.aborted, true);
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('new'));
  assert.equal((await getSession()).user.id, 'new');
  finish(response(session('old')));
  for (let i = 0; i < 30; i++) await Promise.resolve();
  assert.equal((await getSession()).user.id, 'new');
});

test('a late CSRF response after timeout cannot start another token request', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  let finish, calls = 0;
  globalThis.fetch = async () => { calls++; return new Promise(resolve => { finish = resolve; }); };
  const result = getSession();
  const rejected = assert.rejects(result, error => error.kind === 'timeout');
  t.mock.timers.tick(20_000); await rejected;
  finish(response({ token: 'csrf' }));
  for (let i = 0; i < 30; i++) await Promise.resolve();
  assert.equal(calls, 1);
});

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
  assert.deepEqual(JSON.parse(requests[3].options.body), { capabilities: ['protocol-doses'] });
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

test('activation bypasses both a cached token and a request started before activation', async () => {
  let finish, tokens = 0;
  globalThis.fetch = async url => {
    if (url.endsWith('/csrf')) return response({ token: 'csrf' });
    tokens++;
    if (tokens === 1) return new Promise(resolve => { finish = resolve; });
    return response({ ...session('owner'), token: 'subscribed:1' });
  };
  const beforeActivation = getSession();
  while (!finish) await new Promise(resolve => setImmediate(resolve));
  const afterActivation = getSession(undefined, true);
  finish(response({ ...session('owner'), token: 'subscribed:0' }));
  assert.equal((await beforeActivation).token, 'subscribed:0');
  assert.equal((await afterActivation).token, 'subscribed:1');
  assert.equal((await getSession()).token, 'subscribed:1');
  assert.equal(tokens, 2);
});

test('renewal refuses a different account from another browser tab', async () => {
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('first'));
  await login('first@example.test', 'secret');
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('second'));
  await assert.rejects(getSession(undefined, true), /ander tabblad/);
});

test('failed CSRF fetch never submits credentials and failed backend logout still signs out locally', async () => {
  let requests = 0;
  globalThis.fetch = async () => { requests++; return response({}, 503); };
  await assert.rejects(login('test@example.test', 'secret'), /Verbinding/);
  assert.equal(requests, 1);
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : session('first'));
  await login('test@example.test', 'secret');
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : {}, url.endsWith('/csrf') ? 200 : 503);
  await logout();
  assert.equal(await getSession(), null);
  assert.equal(await retryPendingLogout(), false);
  assert.equal(JSON.parse(localStorage.getItem(LOCAL_SIGNOUT_KEY)).pending, true);
});

test('only email confirmation creates a renewable browser session using fresh CSRF', async () => {
  const { register, completeRegistration } = await import('../db/auth.ts');
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push({ url, options });
    if(url.endsWith('/csrf')) return response({token:'registration-csrf'});
    if(url.endsWith('/register')) return response({registration_token:'challenge',email:'new@example.test'},202);
    if(url.endsWith('/token') && !requests.some(r=>r.url.endsWith('/complete'))) return response({},401);
    return response(session('new-user'));
  };
  const input = { name: 'New Owner', email: 'new@example.test', password: 'test-password', password_confirmation: 'test-password' };
  const pending = await register(input);
  assert.equal(await getSession(), null, 'requesting a code does not authenticate');
  await completeRegistration({registration_token:pending.registration_token});
  assert.equal((await getSession()).user.id, 'new-user');
  const verify = requests.find(r=>r.url.endsWith('/complete'));
  assert.equal(verify.options.headers['X-CSRF-TOKEN'],'registration-csrf');
  assert.deepEqual(JSON.parse(verify.options.body),{registration_token:'challenge',capabilities:['protocol-doses']});
  assert.equal(requests[1].url, '/web-session/register');
  assert.equal(requests[1].options.headers['X-CSRF-TOKEN'], 'registration-csrf');
  assert.deepEqual(JSON.parse(requests[1].options.body), input);
  await getSession(undefined, true);
  assert.deepEqual(JSON.parse(requests.at(-1).options.body), { capabilities: ['protocol-doses'] });
});

test('every request that mints a PowerSync token declares per-dose protocol support', async () => {
  const { register, completeRegistration, checkRegistration } = await import('../db/auth.ts');
  const requests = [];
  let tokenStatus = 200;
  globalThis.fetch = async (url, options) => {
    if (url.endsWith('/csrf')) return response({ token: 'csrf' });
    requests.push({ path: url.replace('/web-session/', ''), body: JSON.parse(options.body) });
    if (url.endsWith('/register')) return response({ registration_token: 'challenge', email: 'new@example.test', expires_in: 900 }, 202);
    if (url.endsWith('/status')) return response({ status: 'confirmed' });
    if (url.endsWith('/token')) return response(tokenStatus === 200 ? session('owner') : {}, tokenStatus);
    if (url.endsWith('/logout') || url.endsWith('/forgot-password')) return response({ ok: true });
    return response(session('owner'));
  };
  const minted = () => requests.filter(r => ['login', 'token', 'register/complete'].includes(r.path));

  // Restoring an existing browser session (no login in this tab) renews through /token.
  tokenStatus = 401;
  assert.equal(await getSession(), null);
  tokenStatus = 200;
  assert.equal((await getSession()).user.id, 'owner');
  await getSession(undefined, true);
  await logout();
  // Support logins post the master key through the same login request.
  await login('owner@example.test', 'secret');
  forgetSession();
  const pending = await register({ name: 'New', email: 'new@example.test', password: 'password', password_confirmation: 'password' });
  await checkRegistration({ registration_token: pending.registration_token });
  await completeRegistration({ registration_token: pending.registration_token });
  await requestPasswordReset('owner@example.test');

  assert.deepEqual(minted().map(r => r.path), ['token', 'token', 'token', 'login', 'register/complete']);
  for (const r of minted()) assert.deepEqual(r.body.capabilities, ['protocol-doses'], r.path);
  assert.equal(requests.find(r => r.path === 'login').body.password, 'secret');
  assert.equal(requests.find(r => r.path === 'register/complete').body.registration_token, 'challenge');
  for (const r of requests.filter(r => !minted().includes(r))) assert.equal('capabilities' in r.body, false, r.path);
});

test('registration surfaces validation failures without creating a session', async () => {
  const { register } = await import('../db/auth.ts');
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : { errors: { email: ['Dit e-mailadres is al in gebruik.'] } }, url.endsWith('/csrf') ? 200 : 422);
  await assert.rejects(register({ name: 'Test', email: 'used@example.test', password: 'password', password_confirmation: 'password' }), /al in gebruik/);
});


test('checking email confirmation keeps the browser unauthenticated and sends no password', async () => {
  const { checkRegistration } = await import('../db/auth.ts');
  const requests=[];
  globalThis.fetch = async (url,options)=>{
    requests.push({url,options});
    if(url.endsWith('/csrf')) return response({token:'csrf'});
    if(url.endsWith('/status')) return response({status:'confirmed'});
    return response({},401);
  };
  assert.deepEqual(await checkRegistration({registration_token:'private-token'}),{status:'confirmed'});
  assert.equal(await getSession(),null);
  assert.equal(requests[1].options.headers['X-CSRF-TOKEN'],'csrf');
  assert.deepEqual(JSON.parse(requests[1].options.body),{registration_token:'private-token'});
});


test('password recovery uses the browser session CSRF transport without signing in', async () => {
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push({ url, options });
    return response(url.endsWith('/csrf') ? { token: 'csrf-reset' } : { message: 'Controleer je e-mail.' });
  };
  assert.deepEqual(await requestPasswordReset(' owner@example.test '), { message: 'Controleer je e-mail.' });
  assert.deepEqual(requests.map(r => r.url), ['/web-session/csrf', '/web-session/forgot-password']);
  assert.equal(requests[1].options.headers['X-CSRF-TOKEN'], 'csrf-reset');
  assert.equal(requests[1].options.body, JSON.stringify({ email: 'owner@example.test' }));
  globalThis.fetch = async url => response(url.endsWith('/csrf') ? { token: 'csrf' } : {}, url.endsWith('/csrf') ? 200 : 429);
  await assert.rejects(requestPasswordReset('owner@example.test'), /Te veel pogingen/);
});

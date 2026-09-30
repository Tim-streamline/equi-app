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
  assert.deepEqual(JSON.parse(verify.options.body),{registration_token:'challenge'});
  assert.equal(requests[1].url, '/web-session/register');
  assert.equal(requests[1].options.headers['X-CSRF-TOKEN'], 'registration-csrf');
  assert.deepEqual(JSON.parse(requests[1].options.body), input);
  await getSession(undefined, true);
  assert.equal(requests.at(-1).options.body, '{}');
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

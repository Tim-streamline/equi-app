import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
import { AccountSessionQueue } from '../../expo-app/lib/account-session.ts';

const source = ts.transpileModule(readFileSync(new URL('../lib/browser-push.ts', import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}
function browser(pauseAt) {
  const entered = deferred(), release = deferred(), preferences = new Map(), events = [];
  const session = new AccountSessionQueue();
  let paused = false, subscription = null, bound = null, registered = false, workerCalls = 0, failRemoval = false, failWorker = false;
  async function pause(stage) {
    if (!paused && pauseAt === stage) { paused = true; entered.resolve(); await release.promise; }
  }
  const registration = {
    active: { postMessage: ({ userId }, [port]) => { bound = userId; events.push(['bind', userId]); queueMicrotask(() => port.peer.onmessage()); } },
    pushManager: {
      getSubscription: async () => subscription,
      subscribe: async () => {
        events.push(['subscribe']); await pause('subscription');
        subscription = { endpoint: 'https://push.example/endpoint', options: { applicationServerKey: new Uint8Array([1, 2, 3]).buffer },
          toJSON: () => ({ endpoint: 'https://push.example/endpoint' }),
          unsubscribe: async () => { events.push(['unsubscribe']); subscription = null; return true; } };
        return subscription;
      },
    },
  };
  const serviceWorker = {
    register: async () => {
      if (failWorker) throw new Error('worker unavailable');
      workerCalls++;
      await pause(workerCalls === 1 ? 'worker' : 'binding');
      return registration;
    },
    ready: Promise.resolve(registration),
    getRegistration: async () => registration,
  };
  async function dashboardRequest(path, data) {
    if (path.endsWith('/remove')) {
      events.push(['remove']);
      if (failRemoval) throw new Error('offline');
      registered = false; return {};
    }
    if (data) { events.push(['register-start']); await pause('server'); registered = true; events.push(['register-end']); return {}; }
    events.push(['config']); await pause('config'); return { publicKey: 'AQID' };
  }
  const exports = {};
  vm.runInNewContext(source, {
    exports, require: name => name === '@/lib/account-session' ? { accountSession: session } : { dashboardRequest },
    window: { isSecureContext: true, PushManager: {}, Notification: {} },
    navigator: { serviceWorker },
    Notification: { permission: 'granted', requestPermission: async () => { await pause('permission'); return 'granted'; } },
    localStorage: { getItem: key => preferences.get(key) ?? null, setItem: (key, value) => preferences.set(key, value) },
    MessageChannel: class { constructor() { this.port1 = { close() {} }; this.port2 = { peer: this.port1 }; } },
    atob, Uint8Array, Intl, setTimeout, clearTimeout,
  });
  return { ...exports, session, events, entered: entered.promise, release: release.resolve,
    get subscribed() { return subscription !== null; }, get bound() { return bound; }, get registered() { return registered; },
    set failRemoval(value) { failRemoval = value; }, set failWorker(value) { failWorker = value; },
  };
}

for (const stage of ['permission', 'config', 'worker', 'subscription', 'server', 'binding']) {
  test(`opt-out wins over enrollment paused at ${stage}`, { timeout: 2000 }, async () => {
    const b = browser(stage);
    const enable = b.enableBrowserPush('user', stage === 'permission');
    await b.entered;
    const disable = b.disableBrowserPush('user');
    assert.equal(b.browserPushOptedIn('user'), false, 'opt-out is persisted immediately');
    if (['permission', 'config'].includes(stage)) await disable;
    b.release();
    await Promise.all([enable, disable]);
    assert.equal(b.browserPushOptedIn('user'), false);
    assert.equal(b.subscribed, false);
    assert.equal(b.registered, false);
    assert.equal(b.bound, null);
    assert.ok(!b.events.some(([event, user]) => event === 'bind' && user === 'user'));
    if (stage === 'server') assert.ok(b.events.findIndex(([event]) => event === 'remove') > b.events.findIndex(([event]) => event === 'register-end'));
  });
}

test('background enrollment respects an existing opt-out without contacting the server', async () => {
  const b = browser();
  await b.disableBrowserPush('user');
  const count = b.events.length;
  await b.enableBrowserPush('user', false);
  assert.equal(b.events.length, count);
  assert.equal(b.browserPushOptedIn('user'), false);
});

test('explicit re-enable works and a stale enrollment cannot interfere with it', { timeout: 2000 }, async () => {
  const b = browser('config');
  const stale = b.enableBrowserPush('user', false);
  await b.entered;
  await b.disableBrowserPush('user');
  await b.enableBrowserPush('user');
  b.release(); await stale;
  assert.equal(b.browserPushOptedIn('user'), true);
  assert.equal(b.subscribed, true);
  assert.equal(b.registered, true);
  assert.equal(b.bound, 'user');
  assert.equal(b.events.filter(([event]) => event === 'register-start').length, 1);
});

test('logout invalidates in-flight enrollment and finishes cleanup without a queue deadlock', { timeout: 2000 }, async () => {
  const b = browser('server');
  const enable = b.enableBrowserPush('user', false);
  await b.entered;
  const logout = b.session.transition(() => b.disableBrowserPush());
  b.release(); await Promise.all([enable, logout]);
  assert.equal(b.subscribed, false);
  assert.equal(b.registered, false);
  assert.equal(b.bound, null);
  assert.equal(b.browserPushOptedIn('user'), true, 'logout does not change the saved opt-in preference');
});

for (const failure of ['failRemoval', 'failWorker']) {
  test(`opt-out still unsubscribes when ${failure} fails, and the queue recovers`, async () => {
    const b = browser();
    await b.enableBrowserPush('user');
    b[failure] = true;
    await assert.rejects(b.disableBrowserPush('user'), /offline|worker unavailable/);
    assert.equal(b.subscribed, false);
    assert.equal(b.browserPushOptedIn('user'), false);
    b[failure] = false;
    await b.enableBrowserPush('user');
    assert.equal(b.subscribed, true);
    assert.equal(b.bound, 'user');
  });
}

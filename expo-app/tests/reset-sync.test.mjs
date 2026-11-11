import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as uiStyles from '../components/ui/styles.ts';
import { AccountSessionQueue } from '../lib/account-session.ts';
import * as syncRequests from '../lib/sync-request.ts';
import * as attachmentWrites from '../lib/intake/attachments/storage-writes.ts';

function load(file, modules, globals = {}) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  new Function('require', 'exports', ...Object.keys(globals), code)(name => {
    assert.ok(name in modules, name); return modules[name];
  }, exports, ...Object.values(globals));
  return exports;
}
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
const settle = () => new Promise(resolve => setImmediate(resolve));

function setup({ timeout = false, cleanupClock, values = new Map([['auth', 'keep']]) } = {}) {
  const session = new AccountSessionQueue(), events = [];
  const storage = {
    getItem: async key => values.get(key) ?? null,
    setItem: async (key, value) => { values.set(key, value); },
    removeItem: async key => { values.delete(key); },
  };
  const requests = timeout ? load('../lib/sync-request.ts', {}, {
    setTimeout: (fn, ms) => setTimeout(fn, ms === 60_000 ? 10 : ms),
  }) : syncRequests;
  const api = load('../db/reset-sync.ts', {
    '@react-native-async-storage/async-storage': { default: storage },
    '../lib/account-session': { accountSession: session }, '../lib/sync-request': requests,
  }, cleanupClock ?? {});
  let oldClosed = false;
  const old = {
    pending: ['offline-edit'],
    disconnect: async () => { events.push('disconnect'); },
    write: () => { if (oldClosed) throw new Error('closed'); old.pending.push('late edit'); },
  };
  const fresh = {
    disconnect: async () => { events.push('disconnect fresh'); },
    disconnectAndClear: async options => {
      assert.equal(oldClosed, true); assert.deepEqual(options, { clearLocal: true });
      events.push('clear'); old.pending = [];
    },
    connect: async connector => {
      assert.deepEqual(old.pending, []);
      events.push('connect'); assert.equal((await connector.fetchCredentials()).token, 'fresh-token');
    },
    waitForStatus: async predicate => {
      assert.equal(predicate({ connected: true, hasSynced: false }), false);
      assert.equal(predicate({ connected: false, hasSynced: true }), false);
      assert.equal(predicate({ connected: true, hasSynced: true, dataFlowStatus: { downloadError: new Error() } }), false);
      assert.equal(predicate({ connected: true, hasSynced: true }), true);
      events.push('checkpoint');
    },
  };
  const options = {
    database: old, markerKey: api.resetSyncKey('owner'),
    connector: { fetchCredentials: async () => ({ token: 'later-token' }), uploadData: () => assert.fail('old uploads must not flush') },
    fetchCredentials: async () => { events.push('online'); return { token: 'fresh-token' }; },
    replaceDatabase: async () => { events.push('close old'); oldClosed = true; return fresh; },
    clearLocalData: async () => { events.push('clear files'); },
    onDestructive: () => { events.push('destructive'); }, onDetached: () => { events.push('detach'); },
    onRestartRequired: () => { events.push('restart required'); },
  };
  return { ...api, session, events, values, storage, old, fresh, options };
}

test('reset discards the old queue, replaces the database, waits for fresh data and preserves credentials', async () => {
  const app = setup();
  assert.equal(await app.resetLocalSync(app.options), app.fresh);
  assert.deepEqual(app.events, ['online', 'destructive', 'disconnect', 'detach', 'close old', 'clear', 'clear files', 'connect', 'checkpoint']);
  assert.throws(app.old.write, /closed/);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), false);
  assert.equal(app.values.get('auth'), 'keep');
});

test('offline preflight keeps pending edits and all local data', async () => {
  const app = setup();
  app.options.fetchCredentials = async () => { throw new Error('offline'); };
  await assert.rejects(app.resetLocalSync(app.options), /offline/);
  assert.deepEqual(app.old.pending, ['offline-edit']);
  assert.deepEqual(app.events, []);
  assert.equal(app.session.revision, 0);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), false);
});

test('session changes during preflight cannot clear a different account', async () => {
  const app = setup(), token = deferred();
  app.options.fetchCredentials = () => token.promise;
  const reset = app.resetLocalSync(app.options);
  await app.session.transition(async () => {});
  token.resolve({ token: 'stale' });
  await assert.rejects(reset, /sessie/);
  assert.deepEqual(app.events, []);
});

test('cleanup failure remains recoverable and cannot reconnect a partial reset', async () => {
  const app = setup();
  app.options.clearLocalData = async () => { throw new Error('storage'); };
  await assert.rejects(app.resetLocalSync(app.options), /storage/);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
  assert.ok(!app.events.includes('connect'));
  app.options.database = app.fresh;
  app.options.clearLocalData = async () => {};
  await app.resetLocalSync(app.options);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), false);
});

test('initial download timeout never reports success, even though PowerSync resolves on abort', async () => {
  const app = setup({ timeout: true });
  app.fresh.waitForStatus = (_, signal) => new Promise(resolve => signal.addEventListener('abort', resolve, { once: true }));
  await assert.rejects(app.resetLocalSync(app.options), /verbinding duurt/);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
});

test('the durable marker remains until a real fresh checkpoint arrives', async () => {
  const app = setup(), checkpoint = deferred();
  app.fresh.waitForStatus = () => checkpoint.promise;
  const resetting = app.resetLocalSync(app.options);
  await settle();
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
  checkpoint.resolve(); await resetting;
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), false);
});

test('reset cancels an active upload before discarding its queue', async () => {
  const app = setup(); let aborted = false;
  const upload = syncRequests.syncRequest(20_000, signal => new Promise(resolve => {
    signal.addEventListener('abort', () => { aborted = true; resolve(); });
  }));
  const rejection = assert.rejects(upload, /onderbroken/);
  app.options.onDetached = () => assert.equal(aborted, true);
  await app.resetLocalSync(app.options); await rejection;
});

test('attachment reset waits for current writes, invalidates late picker reads and preserves another owner', async () => {
  const writing = deferred(), records = new Map([['other', 'keep']]);
  const check = attachmentWrites.attachmentWriteCheck('owner');
  const save = attachmentWrites.writeAttachment('owner', async () => { await writing.promise; records.set('owner', 'photo'); });
  await settle();
  const reset = attachmentWrites.resetAttachments('owner', async () => { records.delete('owner'); });
  writing.resolve(); await Promise.all([save, reset]);
  assert.throws(check, /opnieuw ingesteld/);
  assert.deepEqual([...records], [['other', 'keep']]);
});

test('cleanup removes only the selected account dashboard snapshots and attachments', async () => {
  const removed = [], files = [];
  const { clearLocalSyncData } = load('../db/reset-local-data.ts', {
    '@/lib/intake/attachments/storage': { clearAttachments: async id => files.push(id) },
    '@react-native-async-storage/async-storage': { default: {
      getAllKeys: async () => ['horse-dashboard:v4:owner:h:today', 'horse-dashboard:v3:owner:h:today', 'horse-dashboard:v4:other:h:today', 'equinova.auth.v2', 'equi-push-opt:owner'],
      multiRemove: async keys => removed.push(...keys),
    } },
  });
  await clearLocalSyncData('owner');
  assert.deepEqual(files, ['owner']);
  assert.deepEqual(removed, ['horse-dashboard:v4:owner:h:today', 'horse-dashboard:v3:owner:h:today']);
});

test('Reset sync requires explicit confirmation and Cancel never resets', async () => {
  const state = []; let cursor = 0, resets = 0;
  const jsx = (type, props) => ({ type, props });
  const { ResetSyncSettings } = load('../components/sync/ResetSyncSettings.tsx', {
    react: { useState: initial => { const i = cursor++; if (!(i in state)) state[i] = initial;
      return [state[i], value => { state[i] = value; }]; } },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { Platform: { OS: 'web' }, Text: 'Text', View: 'View' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/components/ui/styles': uiStyles,
    '@/db/provider': { useDb: () => ({ resetSync: async () => { resets++; } }) },
  });
  const render = () => { cursor = 0; return ResetSyncSettings(); };
  const buttons = node => !node ? [] : Array.isArray(node) ? node.flatMap(buttons)
    : [...(node.type === 'Button' ? [node.props] : []), ...buttons(node.props?.children)];
  buttons(render())[0].onPress();
  assert.equal(resets, 0);
  buttons(render()).find(b => b.title === 'Annuleren').onPress();
  assert.equal(resets, 0); assert.equal(buttons(render()).length, 1);
  buttons(render())[0].onPress();
  buttons(render()).find(b => b.title.includes('wissen')).onPress();
  await settle(); assert.equal(resets, 1);
});

function hookSetup(resetLocalSync, exclusive) {
  let state;
  const activated = [];
  const { useSyncReset } = load('../db/use-sync-reset.ts', {
    react: {
      useState: initial => { state = initial; return [state, next => { state = typeof next === 'function' ? next(state) : next; }]; },
      useRef: current => ({ current }), useCallback: fn => fn,
    },
    './reset-sync': { resetLocalSync },
  });
  const reset = useSyncReset({ prepare: () => ({}), getDatabase: () => 'old', activate: db => activated.push(db), exclusive });
  return { reset, activated, state: () => state };
}

test('repeated reset taps share one operation and keep the app blocked until it finishes', async () => {
  const pending = deferred(); let calls = 0;
  const app = hookSetup(async options => {
    calls++; options.onDestructive(); options.onDetached(); await pending.promise; return 'fresh';
  });
  const resetting = app.reset.run('owner');
  await app.reset.run('owner');
  assert.equal(calls, 1);
  assert.deepEqual(app.state(), { busy: true, detached: true, error: null, restartRequired: false });
  pending.resolve(); await resetting;
  assert.deepEqual(app.activated, ['fresh']);
  assert.deepEqual(app.state(), { busy: false, detached: false, error: null, restartRequired: false });
});

test('an interrupted reset stays blocked while offline, and recovery supports retry or sign-in', async () => {
  let offline = true;
  const app = hookSetup(async () => { if (offline) throw new Error('offline'); return 'fresh'; });
  await assert.rejects(app.reset.run('owner', true), /offline/);
  assert.equal(app.state().error, 'offline');
  assert.deepEqual(app.activated, []);
  await app.reset.signOut(async () => { throw new Error('offline'); });
  assert.equal(app.state().error, 'Uitloggen is niet gelukt. Probeer opnieuw.');
  offline = false;
  app.reset.retry(); await settle();
  assert.deepEqual(app.activated, ['fresh']);
  assert.equal(app.state().error, null);
});

test('a failed reset can return to sign-in only after logout completes', async () => {
  const app = hookSetup(async options => { options.onDestructive(); options.onDetached(); throw new Error('expired'); });
  await assert.rejects(app.reset.run('owner'), /expired/);
  const logout = deferred();
  const signingOut = app.reset.signOut(() => logout.promise);
  assert.deepEqual(app.state(), { busy: true, detached: true, error: null, restartRequired: false });
  logout.resolve(); await signingOut;
  assert.deepEqual(app.state(), { busy: false, detached: false, error: null, restartRequired: false });
  assert.deepEqual(app.activated, []);
});

function cleanupClock() {
  let expire;
  return {
    globals: {
      setTimeout: (callback, ms) => { assert.equal(ms, 20_000); expire = callback; return 1; },
      clearTimeout: () => { expire = undefined; },
    },
    expire: () => { assert.ok(expire); expire(); },
    armed: () => !!expire,
  };
}

for (const phase of ['marker', 'disconnect', 'replace', 'clear', 'files']) {
  test(`stalled ${phase} shows restart recovery, holds ownership, and cannot continue after its late completion`, async () => {
    const clock = cleanupClock(), pending = deferred();
    const app = setup({ cleanupClock: clock.globals });
    if (phase === 'marker') app.storage.setItem = async (key, value) => { await pending.promise; app.values.set(key, value); };
    if (phase === 'disconnect') app.old.disconnect = () => pending.promise;
    if (phase === 'replace') app.options.replaceDatabase = () => pending.promise;
    if (phase === 'clear') app.fresh.disconnectAndClear = () => pending.promise;
    if (phase === 'files') app.options.clearLocalData = () => pending.promise;
    const resetting = app.resetLocalSync(app.options);
    const rejection = assert.rejects(resetting, /duurt te lang/);
    await settle();
    clock.expire();
    assert.ok(app.events.includes('restart required'));
    assert.equal(await app.hasPendingSyncReset(app.options.markerKey), phase !== 'marker');
    let queued = false;
    const queuedWork = app.session.forSession(app.session.revision, async () => { queued = true; });
    await settle();
    assert.equal(queued, false, 'unfinished cleanup still owns the account queue');
    const eventsAtTimeout = [...app.events];
    pending.resolve(app.fresh);
    await rejection; await queuedWork;
    assert.deepEqual(app.events, eventsAtTimeout, 'no further cleanup or reconnect after the deadline');
    assert.equal(clock.armed(), false);
    assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
    // A fresh execution context sees the marker and can complete recovery.
    const restarted = setup({ values: app.values });
    assert.equal(await restarted.hasPendingSyncReset(restarted.options.markerKey), true);
    await restarted.resetLocalSync(restarted.options);
    assert.equal(await restarted.hasPendingSyncReset(restarted.options.markerKey), false);
  });
}

test('a successful cleanup cancels the watchdog before downloading', async () => {
  const clock = cleanupClock(), app = setup({ cleanupClock: clock.globals });
  app.fresh.connect = async () => { assert.equal(clock.armed(), false); };
  const remove = app.storage.removeItem;
  app.storage.removeItem = async key => { assert.equal(clock.armed(), true); await remove(key); };
  await app.resetLocalSync(app.options);
  assert.equal(clock.armed(), false);
  assert.ok(!app.events.includes('restart required'));
});

for (const outcome of ['success', 'failure']) {
  test(`stalled final marker removal keeps restart recovery after late ${outcome}`, async () => {
    const clock = cleanupClock(), pending = deferred(), app = setup({ cleanupClock: clock.globals });
    let removing = false, held = false, logouts = 0, calls = 0;
    app.storage.removeItem = async key => {
      removing = true;
      await pending.promise;
      if (outcome === 'failure') throw new Error('late storage failure');
      app.values.delete(key);
    };
    const hook = hookSetup(options => {
      calls++;
      return app.resetLocalSync({ ...app.options,
        onDestructive: options.onDestructive, onDetached: options.onDetached,
        onRestartRequired: options.onRestartRequired });
    }, async (_id, work) => {
      held = true;
      try { await work(); } finally { held = false; }
    });
    const resetting = hook.reset.run('owner');
    const rejection = assert.rejects(resetting, outcome === 'success' ? /duurt te lang/ : /late storage failure/);
    await settle();
    assert.equal(removing, true);
    assert.ok(app.events.includes('checkpoint'));
    assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
    clock.expire();
    const recovery = hook.state();
    assert.equal(recovery.restartRequired, true);
    assert.equal(recovery.busy, false);
    assert.equal(recovery.detached, true);
    assert.equal(held, true);
    let queueReleased = false;
    const queued = app.session.forSession(app.session.revision, async () => { queueReleased = true; });
    hook.reset.retry(); await hook.reset.signOut(async () => { logouts++; });
    await settle();
    assert.equal(queueReleased, false);
    assert.equal(calls, 1); assert.equal(logouts, 0);
    pending.resolve(); await rejection; await queued;
    assert.equal(held, false);
    assert.equal(clock.armed(), false);
    assert.deepEqual(hook.activated, []);
    assert.deepEqual(hook.state(), recovery);
    await hook.reset.run('owner'); await hook.reset.signOut(async () => { logouts++; });
    assert.equal(calls, 1); assert.equal(logouts, 0);
    // A restart either opens the already refreshed data, or resumes reset if
    // removal failed. Neither case requires the old runtime to accept its result.
    const restarted = setup({ values: app.values });
    assert.equal(await restarted.hasPendingSyncReset(app.options.markerKey), outcome === 'failure');
    if (outcome === 'failure') await restarted.resetLocalSync(restarted.options);
    assert.equal(await restarted.hasPendingSyncReset(app.options.markerKey), false);
  });
}

test('marker removal queued behind other work cannot begin after its deadline', async () => {
  const clock = cleanupClock(), app = setup({ cleanupClock: clock.globals }), blocker = deferred();
  let blocking, removals = 0;
  app.fresh.waitForStatus = async () => {
    blocking = app.session.forSession(app.session.revision, () => blocker.promise);
  };
  app.storage.removeItem = async () => { removals++; };
  const resetting = app.resetLocalSync(app.options);
  const rejection = assert.rejects(resetting, /duurt te lang/);
  await settle(); clock.expire();
  blocker.resolve(); await blocking; await rejection;
  assert.equal(removals, 0);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
  assert.equal(clock.armed(), false);
});

test('an ordinary marker removal error clears the watchdog and remains retryable', async () => {
  const clock = cleanupClock(), app = setup({ cleanupClock: clock.globals });
  const remove = app.storage.removeItem;
  app.storage.removeItem = async () => { throw new Error('storage unavailable'); };
  await assert.rejects(app.resetLocalSync(app.options), /storage unavailable/);
  assert.equal(clock.armed(), false);
  assert.ok(!app.events.includes('restart required'));
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), true);
  app.storage.removeItem = remove;
  app.options.database = app.fresh;
  await app.resetLocalSync(app.options);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), false);
});

test('a reset waiting for the account queue times out without starting cleanup later', async () => {
  const clock = cleanupClock(), app = setup({ cleanupClock: clock.globals }), blocker = deferred();
  const blocking = app.session.transition(() => blocker.promise);
  const resetting = app.resetLocalSync(app.options);
  const rejection = assert.rejects(resetting, /duurt te lang/);
  await settle(); clock.expire();
  assert.deepEqual(app.events, ['online', 'restart required']);
  blocker.resolve(); await blocking; await rejection;
  assert.deepEqual(app.events, ['online', 'restart required']);
  assert.equal(await app.hasPendingSyncReset(app.options.markerKey), false);
});

test('restart recovery keeps the exclusive lock while stalled and blocks retry/logout even after late settlement', async () => {
  const pending = deferred(); let callbacks, calls = 0, held = false, logouts = 0;
  const app = hookSetup(async options => {
    calls++; callbacks = options; options.onDestructive();
    await pending.promise;
    throw new Error('late storage error');
  }, async (_id, work) => {
    held = true;
    try { await work(); } finally { held = false; }
  });
  const resetting = app.reset.run('owner');
  const rejection = assert.rejects(resetting, /late storage error/);
  callbacks.onRestartRequired();
  const recovery = app.state();
  assert.equal(recovery.busy, false);
  assert.equal(recovery.detached, true);
  assert.equal(recovery.restartRequired, true);
  assert.equal(held, true);
  app.reset.retry(); await app.reset.signOut(async () => { logouts++; });
  assert.equal(calls, 1); assert.equal(logouts, 0);
  pending.resolve(); await rejection;
  assert.equal(held, false);
  await app.reset.run('owner'); app.reset.retry();
  await app.reset.signOut(async () => { logouts++; });
  assert.equal(calls, 1); assert.equal(logouts, 0);
  assert.deepEqual(app.state(), recovery);
  assert.deepEqual(app.activated, []);
});

for (const platform of ['web', 'android', 'ios']) {
  test(`${platform} restart screen hides retry/logout and provides platform recovery instructions`, () => {
    const jsx = (type, props) => ({ type, props }); let reloads = 0;
    const { SyncResetBoundary } = load('../components/sync/SyncResetBoundary.tsx', {
      'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
      'react-native': { ActivityIndicator: 'Spinner', Modal: 'Modal', Platform: { OS: platform }, Text: 'Text', View: 'View' },
      '@/components/ui/Button': { Button: 'Button' },
    }, { window: { location: { reload: () => { reloads++; } } } });
    const tree = SyncResetBoundary({ state: { busy: false, detached: true, restartRequired: true, error: 'stalled' },
      retry: () => assert.fail('unsafe retry'), onSignOut: () => assert.fail('unsafe logout'), children: 'normal app' });
    const nodes = node => !node ? [] : Array.isArray(node) ? node.flatMap(nodes)
      : [node, ...nodes(node.props?.children)];
    const all = nodes(tree), buttons = all.filter(n => n.type === 'Button');
    assert.ok(!all.includes('normal app'));
    assert.ok(!all.some(n => n.type === 'Spinner'));
    assert.equal(all.find(n => n.type === 'Modal').props.visible, true);
    if (platform === 'web') {
      assert.deepEqual(buttons.map(b => b.props.title), ['Pagina opnieuw laden']);
      buttons[0].props.onPress(); assert.equal(reloads, 1);
    } else {
      assert.equal(buttons.length, 0);
      assert.ok(all.some(n => typeof n === 'string' && n.includes('Sluit de app volledig')));
    }
  });
}

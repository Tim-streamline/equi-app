import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { subscriptionActive } from '../lib/subscription.ts';

const at = new Date('2026-10-15T10:00:00Z');
test('subscription dates enforce expiry offline, including UTC timestamps and future cancellation', () => {
  const current = { status: 'active', started_at: '2026-10-01', paid_through: '2026-10-15 10:01:00' };
  assert.equal(subscriptionActive(current, at), true);
  assert.equal(subscriptionActive(current, new Date('2026-10-15T10:01:00Z')), false);
  for (const patch of [{ status: 'cancelled' }, { started_at: '2026-10-16' }, { ended_at: '2026-10-15 09:59:59' }, { cancelled_at: '2026-10-15' }, { paid_through: 'invalid' }]) {
    assert.equal(subscriptionActive({ ...current, ...patch }, at), false);
  }
  assert.equal(subscriptionActive({ ...current, cancelled_at: '2026-10-16' }, at), true);
});

function gate() {
  let rows = [], loading = false, synced = true, loggedIn = true, clock = at;
  let tick, foreground, cursor, dirty, refreshCalls = 0, refreshSignal;
  let refresh = async () => {};
  const states = [], effects = [], pendingEffects = [];
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const refreshSubscriptionAccess = signal => { refreshCalls++; refreshSignal = signal; return refresh(signal); };
  const modules = {
    react: {
      useState: initial => {
        const i = cursor++;
        if (!(i in states)) states[i] = typeof initial === 'function' ? initial() : initial;
        return [states[i], next => {
          const value = typeof next === 'function' ? next(states[i]) : next;
          if (!Object.is(value, states[i])) { states[i] = value; dirty = true; }
        }];
      },
      useEffect: (fn, deps) => {
        const i = cursor++, previous = effects[i];
        if (!previous || deps.some((value, j) => !Object.is(value, previous.deps[j]))) {
          pendingEffects.push(() => { previous?.cleanup?.(); effects[i] = { deps, cleanup: fn() }; });
        }
      },
    },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { ActivityIndicator: 'Spinner', Pressable: 'Button', Text: 'Text', View: 'View', AppState: { addEventListener: (_event, fn) => { foreground = fn; return { remove() {} }; } } },
    '@powersync/react': { useQuery: (sql, params) => { assert.ok(sql.includes("p.slug != 'free'")); assert.deepEqual(params, ['owner']); return { data: rows, isLoading: loading }; }, useStatus: () => ({ hasSynced: synced }) },
    '@/db/provider': { useDb: () => ({ currentUserId: 'owner', isLoggedIn: loggedIn, refreshSubscriptionAccess }) },
    '@/lib/subscription': { subscriptionActive },
    './SubscriptionRenewal': { SubscriptionRenewal: 'Renewal' },
  };
  const exports = {};
  const source = readFileSync(new URL('../components/subscription/SubscriptionGate.tsx', import.meta.url), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  new Function('require', 'exports', 'setInterval', 'clearInterval', 'Date', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports, fn => { tick = fn; return 1; }, () => {}, class extends Date { constructor() { super(clock); } });
  return {
    render: () => {
      let result;
      do {
        cursor = 0; dirty = false;
        result = exports.SubscriptionGate({ children: 'PROTECTED DEEP LINK' });
        pendingEffects.splice(0).forEach(fn => fn());
      } while (dirty);
      return result;
    },
    set rows(value) { rows = value; }, set synced(value) { synced = value; }, set loading(value) { loading = value; }, set loggedIn(value) { loggedIn = value; },
    set refresh(value) { refresh = value; }, get calls() { return refreshCalls; }, get signal() { return refreshSignal; },
    tick: () => { clock = new Date('2026-10-15T10:00:02Z'); tick(); },
    foreground: () => { clock = new Date('2026-10-15T10:00:02Z'); foreground(); },
    close: () => effects.forEach(effect => effect?.cleanup?.()),
  };
}
const active = [{ status: 'active', paid_through: '2099-01-01' }];
const settle = () => new Promise(resolve => setImmediate(resolve));
const find = (node, type) => node?.type === type ? node : [node?.props?.children].flat(Infinity).map(child => child && typeof child === 'object' ? find(child, type) : undefined).find(Boolean);

test('unsubscribed deep links show only renewal', () => {
  const app = gate(); assert.equal(app.render().type, 'Renewal');
  app.loggedIn = false; assert.equal(app.render(), 'PROTECTED DEEP LINK'); app.close();
});
test('cached active subscribers and initial query hydration need no network', () => {
  for (const hydrate of [false, true]) {
    const app = gate(); app.synced = false;
    if (hydrate) { app.loading = true; app.render(); }
    app.rows = active; app.loading = false;
    assert.equal(app.render(), 'PROTECTED DEEP LINK');
    assert.equal(app.calls, 0); app.close();
  }
});
test('clock and foreground events expire access without a network request', () => {
  for (const event of ['tick', 'foreground']) {
    const app = gate(); app.rows = [{ status: 'active', paid_through: '2026-10-15T10:00:01Z' }];
    assert.equal(app.render(), 'PROTECTED DEEP LINK'); app[event]();
    assert.equal(app.render().type, 'Renewal'); assert.equal(app.calls, 0); app.close();
  }
});
test('activation refreshes once and gates protected routes until the new sync finishes', async () => {
  const app = gate(); let finish;
  app.refresh = () => new Promise(resolve => { finish = resolve; });
  assert.equal(app.render().type, 'Renewal');
  app.rows = active;
  assert.ok(find(app.render(), 'Spinner')); assert.equal(app.calls, 1);
  app.tick(); app.render(); assert.equal(app.calls, 1);
  finish(); await settle();
  assert.equal(app.render(), 'PROTECTED DEEP LINK'); assert.equal(app.calls, 1); app.close();
});
test('failed activation offers retry and a second activation gets its own refresh', async () => {
  const app = gate(); app.refresh = async () => { throw new Error('Offline'); };
  app.render(); app.rows = active; app.render(); await settle();
  const retry = find(app.render(), 'Button'); assert.ok(retry); assert.equal(app.calls, 1);
  app.refresh = async () => {}; retry.props.onPress(); app.render(); await settle();
  assert.equal(app.render(), 'PROTECTED DEEP LINK'); assert.equal(app.calls, 2);
  app.rows = []; assert.equal(app.render().type, 'Renewal');
  app.rows = active; app.render(); await settle();
  assert.equal(app.render(), 'PROTECTED DEEP LINK'); assert.equal(app.calls, 3); app.close();
});
test('unmount aborts an activation and ignores its late result', async () => {
  const app = gate(); let finish;
  app.refresh = () => new Promise(resolve => { finish = resolve; });
  app.render(); app.rows = active; app.render(); app.close();
  assert.equal(app.signal.aborted, true); finish(); await settle();
});

test('activation button posts to the server and reports success or a retryable failure', async () => {
  for (const fails of [false, true]) {
    let cursor = 0, finish;
    const states = [];
    const calls = [];
    const jsx = (type, props) => ({ type, props });
    const modules = {
      react: { useState: initial => {
        const index = cursor++;
        if (!(index in states)) states[index] = initial;
        return [states[index], value => { states[index] = value; }];
      } },
      'react/jsx-runtime': { jsx, jsxs: jsx },
      'react-native': { Text: 'Text', View: 'View' },
      '@/components/ui/Button': { Button: 'Button' },
      '@/hooks/useHorseDashboard': { dashboardRequest: (path, body) => {
        calls.push([path, body]);
        return new Promise((resolve, reject) => { finish = () => fails ? reject(new Error('Offline')) : resolve({ subscribed: true }); });
      } },
    };
    const exports = {};
    const source = readFileSync(new URL('../components/subscription/SubscriptionRenewal.tsx', import.meta.url), 'utf8');
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
    new Function('require', 'exports', code)(name => modules[name], exports);
    const render = () => { cursor = 0; return exports.SubscriptionRenewal(); };
    const button = find(render(), 'Button');
    assert.equal(button.props.title, 'Abonnement activeren');
    button.props.onPress();
    assert.equal(find(render(), 'Button').props.disabled, true);
    assert.deepEqual(calls, [['/api/subscription/renew', {}]]);
    finish(); await settle();
    const result = render();
    assert.equal(find(result, 'Button').props.disabled, false);
    const message = result.props.children.find(child => child?.props?.accessibilityRole === 'alert').props.children;
    assert.match(message, fails ? /probeer het opnieuw/ : /Je abonnement is actief/);
  }
});

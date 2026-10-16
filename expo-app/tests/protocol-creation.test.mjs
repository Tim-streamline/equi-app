import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

function screen() {
  let cursor = 0, horse = 'rolo', resolve, reject;
  const state = [], requests = [], routes = [], session = { revision: 0 };
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react: {
      useCallback: fn => fn, useEffect() {},
      useState: initial => { const i = cursor++; if (!(i in state)) state[i] = i === 0 ? { content: {} } : initial; return [state[i], value => { state[i] = value; }]; },
      useRef: initial => { const i = cursor++; return state[i] ??= { current: initial }; },
    },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { ActivityIndicator: 'Spinner', Text: 'Text', View: 'View' },
    'expo-router': { useFocusEffect() {}, router: { push: route => routes.push(route), replace: route => routes.push(route) } },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    '@/db/hooks': { useCurrentHorseId: () => horse, useHorse: () => ({ name: 'Nova' }) }, '@/db/auth': { getApiBaseUrl: () => '' },
    '@/components/ui/SubHeader': { SubHeader: 'SubHeader' }, '@/components/ui/Button': { Button: 'Button' },
    '@/components/plus/PlusPage': { PlusPage: 'PlusPage' },
    '@/hooks/useHorseDashboard': { dashboardRequest: (...args) => { requests.push(args); return new Promise((ok, fail) => { resolve = ok; reject = fail; }); } },
    '@/lib/account-session': { accountSession: session },
  };
  const exports = {};
  const source = readFileSync(new URL('../app/protocol-purchase.tsx', import.meta.url), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return {
    page: () => { cursor = 0; return exports.default().props.children.find(child => child.type === 'PlusPage').props; },
    requests, routes, session, resolve: () => resolve({ protocolId: 'protocol', bookingId: 'intake-rolo' }), reject: () => reject(new Error('Offline')),
    set horse(value) { horse = value; },
  };
}

test('purchase submits the selected horse once and opens the returned intake', async () => {
  const app = screen();
  const create = app.page().onIntake;
  const pending = create();
  assert.equal(await create(), false);
  assert.equal(app.page().busy, true);
  assert.deepEqual(app.requests, [['/api/protocol-purchases/create', { horse_id: 'rolo' }]]);
  app.resolve(); assert.equal(await pending, true);
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'intake-rolo' } }]);
});

test('failed creation stays on the page and can be retried', async () => {
  const app = screen(); const first = app.page().onIntake();
  app.reject(); assert.equal(await first, false);
  assert.match(app.page().error, /probeer het opnieuw/);
  assert.deepEqual(app.routes, []); assert.equal(app.page().busy, false);
  const retry = app.page().onIntake(); app.resolve(); assert.equal(await retry, true);
});

test('a response cannot open another horse or account intake after a switch', async () => {
  for (const change of ['horse', 'account']) {
    const app = screen(); const pending = app.page().onIntake();
    if (change === 'horse') app.horse = 'nova'; else app.session.revision++;
    app.page(); app.resolve(); assert.equal(await pending, false);
    assert.deepEqual(app.routes, []);
  }
});

test('without a selected horse the purchase action opens horse creation', async () => {
  const app = screen(); app.horse = '';
  assert.equal(await app.page().onIntake(), false);
  assert.deepEqual(app.requests, []); assert.deepEqual(app.routes, ['/onboarding/add-horse']);
});

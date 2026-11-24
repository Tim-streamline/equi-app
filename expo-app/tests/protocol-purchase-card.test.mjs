import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import { protocolStyleGlobals } from './protocol-styles.mjs';

const source = readFileSync(new URL('../app/(tabs)/(pager)/protocol.tsx', import.meta.url), 'utf8');
const screen = source.slice(source.indexOf('export default function ProtocolListScreen()'), source.indexOf('\nfunction ProtocolPreparation('));
const code = ts.transpileModule(screen, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
function app(overrides = {}) {
  let horseId = 'rolo', data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: true, protocol: null };
  const routes = [];
  let header;
  const jsx = (type, props) => ({ type, props });
  const dependencies = {
    ...Object.fromEntries(['View', 'Text', 'Pressable', 'ScrollView', 'ActivityIndicator', 'PurchasedProtocols', 'Button'].map(name => [name, name])),
    useState: initial => [initial, () => {}], useEffect() {}, useMemo: fn => fn(), useRef: initial => ({ current: initial }),
    useLocalSearchParams: () => ({}), protocolTab: () => null,
    useDb: () => ({ selectHorse() {} }),
    useHorseDashboard: () => ({ horseId, data, refresh() {} }),
    useTabBarPadding: () => 76,
    ...Object.fromEntries(['HorseSwitcher', 'OfflineStrip', 'IconButton', 'ShoppingBag', 'ProtocolTabs'].map(name => [name, name])),
    ...Object.fromEntries(['Today', 'Calendar', 'Nutrition', 'CareAdvice', 'ProtocolAnalysis', 'Sheet', 'WeeklySheet', 'Supplement', 'Label', 'ProtocolOrderList'].map(name => [name, name])),
    HEADER_ICON: '#466362', useHeaderAction: action => { header = action; },
    router: { push: route => routes.push(route) },
    scopeOrderItems: (items, scope) => scope === 'all' ? items : [],
    createOrderTicks: () => ({ toggle() {} }), dashboardRequest: async () => ({}),
    shownOrderedKeys: (overlay, horse, protocol, server) => server, latestDashboardRequest: () => 0,
    ...protocolStyleGlobals, ...overrides,
  };
  const exports = {};
  new Function('require', 'exports', ...Object.keys(dependencies), code)(() => ({ jsx, jsxs: jsx }), exports, ...Object.values(dependencies));
  return {
    card: () => flatten(exports.default()).find(node => node?.props?.accessibilityRole === 'link'), routes,
    nodes: () => flatten(exports.default()),
    addHorse: () => flatten(exports.default()).find(node => node?.type === 'Button' && node.props.title === 'Paard toevoegen'),
    headerAction: () => { exports.default(); return flatten(header); },
    set horseId(value) { horseId = value; }, set data(value) { data = value; },
  };
}

test('an eligible selected horse has a protocol purchase card opening the purchase page', () => {
  const screen = app();
  assert.equal(screen.card().props.accessibilityLabel, 'Protocol kopen voor Rolo');
  screen.card().props.onPress();
  assert.deepEqual(screen.routes, ['/protocol-purchase']);
  screen.horseId = 'nova';
  assert.equal(screen.card(), undefined, 'do not show the previous horse offer while switching');
  screen.data = { horse: { id: 'nova', name: 'Nova' }, showPlusUpsell: true };
  assert.equal(screen.card().props.accessibilityLabel, 'Protocol kopen voor Nova');
});

test('no purchase card while loading, without a horse, or when an existing protocol/intake blocks purchase', () => {
  const screen = app();
  screen.data = null; assert.equal(screen.card(), undefined);
  screen.data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: false };
  assert.equal(screen.card(), undefined);
  screen.horseId = ''; screen.data = { horse: { id: '', name: '' }, showPlusUpsell: true };
  assert.equal(screen.card(), undefined);
});

test('a completed plan stays readable with the purchase card for the next protocol below it', () => {
  const screen = app();
  const plan = { id: 'p1', completed: true, orderItems: [], phases: [] };
  screen.data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: true, protocol: plan };
  assert.equal(screen.card().props.accessibilityLabel, 'Protocol kopen voor Rolo');
  assert.ok(screen.nodes().some(node => node.type === 'Today'), 'the finished plan is still shown');
  screen.data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: false, protocol: plan };
  assert.equal(screen.card(), undefined, 'not while a new protocol or intake is already under way');
});

test('a completed plan lists the horse\'s still actionable intakes below it, without itself', () => {
  const screen = app();
  const plan = { id: 'p1', completed: true, orderItems: [], phases: [] };
  screen.data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: false, protocol: plan };
  const list = screen.nodes().find(node => node.type === 'PurchasedProtocols');
  assert.deepEqual(list.props, { excludeProtocolIds: ['p1'], actionableOnly: true });
  screen.data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: false, protocol: { ...plan, completed: false } };
  assert.ok(!screen.nodes().some(node => node.type === 'PurchasedProtocols'), 'a running plan keeps its own Vandaag');
});

test('without a horse the protocol tab explains why it is empty and offers to add one', () => {
  const screen = app();
  assert.equal(screen.addHorse(), undefined);
  screen.horseId = ''; screen.data = { horse: { id: '', name: '' }, showPlusUpsell: false };
  screen.addHorse().props.onPress();
  assert.deepEqual(screen.routes, ['/onboarding/add-horse']);
});

test('the selected horse and the order list sit in the header bar', () => {
  const screen = app();
  assert.ok(screen.headerAction().some(node => node.type === 'HorseSwitcher'));
  assert.ok(!screen.headerAction().some(node => node.type === 'IconButton'), 'no order list without a protocol');
  screen.data = { horse: { id: 'rolo', name: 'Rolo' }, showPlusUpsell: false, protocol: { id: 'p1', orderItems: [], phases: [] } };
  const orders = screen.headerAction().find(node => node.type === 'IconButton');
  assert.equal(orders.props.accessibilityLabel, 'Open bestellijst');
  assert.ok(screen.headerAction().some(node => node.type === 'HorseSwitcher'));
});

test('an order save survives switching away and cannot replay over a later cached dashboard', async () => {
  const orderLogic = {};
  const source = readFileSync(new URL('../lib/protocol-order-ticks.ts', import.meta.url), 'utf8');
  new Function('exports', ts.transpileModule(source, {compilerOptions: {module: ts.ModuleKind.CommonJS}}).outputText)(orderLogic);
  const slots = [], effects = [], writes = [];
  let cursor = 0, horse = 'a', revision = 1, serverKeys = [];
  const memo = (fn, deps) => {
    const i = cursor++;
    if (!slots[i] || deps.some((v, n) => !Object.is(v, slots[i].deps[n]))) slots[i] = { deps, value: fn() };
    return slots[i].value;
  };
  const screen = app({
    ...orderLogic,
    useState(initial) {
      const i = cursor++;
      if (!(i in slots)) slots[i] = initial;
      return [slots[i], value => { slots[i] = typeof value === 'function' ? value(slots[i]) : value; }];
    },
    useMemo: memo, useRef: initial => memo(() => ({current: initial}), []),
    useEffect: (fn, deps) => memo(() => effects.push(fn), deps),
    useHorseDashboard: () => ({horseId: horse, revision, refresh() {}, data: {
      horse: {id: horse}, protocol: {id: horse + '-plan', phases: [], orderItems: [], orderedKeys: serverKeys},
    }}),
    latestDashboardRequest: () => revision,
    dashboardRequest: () => new Promise((resolve, reject) => writes.push({resolve, reject})),
  });
  const render = () => {
    cursor = 0;
    const list = screen.nodes().find(node => node.type === 'ProtocolOrderList').props;
    effects.splice(0).forEach(effect => effect());
    return list;
  };
  const flush = () => new Promise(setImmediate);
  render().onToggle(['a1'], true);
  await flush();
  horse = 'b';
  render().onToggle(['b1'], true);
  await flush();
  writes[0].reject(new Error('offline'));
  await flush();
  assert.deepEqual(render().orderedKeys, ['b1']);
  horse = 'a';
  assert.deepEqual(render().orderedKeys, [], 'the failed write settled while another horse was displayed');
  horse = 'b';
  writes[1].resolve({orderedKeys: ['b1']});
  await flush();
  assert.deepEqual(render().orderedKeys, ['b1']);
  revision = 2;
  assert.deepEqual(render().orderedKeys, [], 'a second device removed the tick before this refresh');
  revision = 0;
  assert.deepEqual(render().orderedKeys, [], 'reloading cached data cannot resurrect the acknowledged overlay');
});

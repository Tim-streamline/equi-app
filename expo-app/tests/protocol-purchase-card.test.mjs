import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../app/(tabs)/(pager)/protocol.tsx', import.meta.url), 'utf8');
const screen = source.slice(source.indexOf('export default function ProtocolListScreen()'), source.indexOf('\nfunction Card('));
const code = ts.transpileModule(screen, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
function app() {
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
    ...Object.fromEntries(['Today', 'Calendar', 'Nutrition', 'CareAdvice', 'ProtocolAnalysis', 'Sheet', 'WeeklySheet', 'Supplement', 'Label'].map(name => [name, name])),
    HEADER_ICON: '#466362', useHeaderAction: action => { header = action; },
    router: { push: route => routes.push(route) },
  };
  const exports = {};
  new Function('require', 'exports', ...Object.keys(dependencies), code)(() => ({ jsx, jsxs: jsx }), exports, ...Object.values(dependencies));
  return {
    card: () => flatten(exports.default()).find(node => node?.props?.accessibilityRole === 'link'), routes,
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

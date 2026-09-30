import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../app/(tabs)/(pager)/protocol.tsx', import.meta.url), 'utf8');
const sheet = source.slice(source.indexOf('function Sheet('), source.indexOf('function WeeklySheet('));
const { outputText } = ts.transpileModule(`export ${sheet}`, {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
});

function render(height, insets) {
  const jsx = (type, props) => ({ type, props });
  const dependencies = {
    ...Object.fromEntries(['View', 'Text', 'ScrollView', 'Pressable', 'Modal', 'SafeAreaView', 'Button'].map(name => [name, name])),
    useWindowDimensions: () => ({ width: 320, height }),
    useSafeAreaInsets: () => insets,
  };
  const exports = {};
  new Function('require', 'exports', ...Object.keys(dependencies), outputText)(
    () => ({ jsx, jsxs: jsx }), exports, ...Object.values(dependencies),
  );
  const cards = Array.from({ length: 30 }, (_, index) => jsx('HerbCard', {
    name: `Kruid ${index + 1}`, dosage: `${index + 1} gram`,
    instructions: 'Een lange toelichting die over meerdere regels doorloopt. '.repeat(20),
  }));
  return exports.Sheet({ visible: true, title: 'Fase met veel kruiden', subtitle: 'Week 1–8', children: cards, onClose() {} });
}

function find(node, predicate) {
  if (!node || typeof node !== 'object') return undefined;
  if (predicate(node)) return node;
  return [node.props?.children].flat(Infinity).map(child => find(child, predicate)).find(Boolean);
}

for (const height of [360, 568, 640, 844]) {
  test(`phase content has a bounded scrolling area on a ${height}px-high screen`, () => {
    const tree = render(height, { top: 24, bottom: 24 });
    const panel = find(tree, node => node.props.testID === 'protocol-sheet-panel');
    assert.ok(panel, 'The sheet needs a separately bounded panel');
    assert.ok(panel.props.style.height <= height - 24);
    assert.ok(panel.props.style.height >= height * 0.85);
    const scroll = find(panel, node => node.type === 'ScrollView');
    assert.equal(scroll.props.style.flex, 1);
    assert.equal(scroll.props.style.minHeight, 0);
    assert.equal(scroll.props.nestedScrollEnabled, true);
    assert.ok(scroll.props.contentContainerStyle.paddingBottom >= 24);
    assert.equal(scroll.props.contentContainerStyle.height, undefined);
    assert.ok(find(scroll, node => node.props.children === 'Fase met veel kruiden'));
    assert.ok(find(scroll, node => node.props.children === 'Week 1–8'));
    assert.ok(find(scroll, node => node.type === 'HerbCard' && node.props.name === 'Kruid 30'));
    assert.equal(find(scroll, node => node.type === 'Button'), undefined);
    assert.equal(find(scroll, node => node.props.testID === 'protocol-sheet-handle'), undefined);
    assert.ok(find(panel, node => node.props.testID === 'protocol-sheet-handle'));
    const footer = find(panel, node => node.props.testID === 'protocol-sheet-footer');
    assert.ok(footer.props.style.paddingBottom >= 24);
    assert.equal(footer.props.style.flexShrink, 0);
    assert.ok(find(footer, node => node.type === 'Button' && node.props.title === 'Sluiten'));
  });
}

test('the backdrop is separate from the scroll gestures and the modal blocks the background', () => {
  const tree = render(640, { top: 0, bottom: 0 });
  assert.equal(tree.type, 'Modal');
  assert.equal(tree.props.visible, true);
  const backdrop = find(tree, node => node.props.testID === 'protocol-sheet-backdrop');
  assert.equal(backdrop.type, 'Pressable');
  assert.equal(find(backdrop, node => node.type === 'ScrollView'), undefined);
});

test('weekupdate input and save/cancel actions share the bounded keyboard scroll area and save once', async () => {
  const weekly = source.slice(source.indexOf('function WeeklySheet('));
  const code = ts.transpileModule(`export ${weekly}`, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
  const jsx = (type, props) => ({ type, props });
  let hook = 0, saved = 0, closed = 0;
  const state = ['Een weekupdate', false], requests = [];
  const dependencies = {
    ...Object.fromEntries(['Text', 'TextInput', 'KeyboardAvoidingView', 'KeyboardScrollView', 'Pressable', 'Modal', 'SafeAreaView', 'Button'].map(name => [name, name])),
    Platform: { OS: 'android' },
    useState: initial => { const index = hook++; return [state[index] ?? initial, value => { state[index] = value; }]; },
    dashboardRequest: async (...args) => requests.push(args), deviceTimezone: () => 'Europe/Amsterdam',
    Alert: { alert: () => assert.fail('Unexpected save failure') },
  };
  const exports = {};
  new Function('require', 'exports', ...Object.keys(dependencies), code)(() => ({ jsx, jsxs: jsx }), exports, ...Object.values(dependencies));
  const props = { visible: true, horseId: 'horse', protocolId: 'protocol', onClose: () => closed++, onSaved: async () => saved++ };
  const tree = exports.WeeklySheet(props);
  const panel = find(tree, node => node.type === 'SafeAreaView');
  assert.equal(panel.props.style.maxHeight, '100%');
  assert.equal(panel.props.style.flexShrink, 1);
  const scroll = find(panel, node => node.type === 'KeyboardScrollView');
  assert.ok(find(scroll, node => node.type === 'TextInput' && node.props.multiline));
  assert.ok(find(scroll, node => node.type === 'Pressable' && node.props.onPress === props.onClose));
  const button = find(scroll, node => node.type === 'Button');
  assert.equal(button.props.title, 'Weekupdate opslaan');
  button.props.onPress();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(requests, [['/api/horses/horse/weekly-update', { protocol_id: 'protocol', note: 'Een weekupdate', timezone: 'Europe/Amsterdam' }]]);
  assert.equal(saved, 1); assert.equal(closed, 1); assert.equal(state[0], '');
});

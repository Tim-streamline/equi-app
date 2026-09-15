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

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const jsx = (type, props) => ({ type, props });
const flatten = n => Array.isArray(n) ? n.flatMap(flatten) : n && typeof n === 'object' ? [n, ...flatten(n.props?.children)] : [n];
const text = n => flatten(n).filter(n => typeof n === 'string').join('');
const exports = {}, opened = [];
const code = ts.transpileModule(readFileSync(new URL('../components/protocol/ProtocolOrderList.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;
new Function('require', 'exports', code)(name => ({
  'react/jsx-runtime': { jsx, jsxs: jsx },
  'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable', Linking: { openURL: async url => opened.push(url) }, Alert: { alert() {} } },
})[name], exports);
const item = { id: 'product', name: 'Heemstwortel', quantity: 630, unit: 'g', quantityLabel: '630 g',
  dosage: '15 g', instructions: 'Laat 30 minuten trekken.', description: 'Werking van het product',
  purchaseOptions: [{ url: 'https://example.com/heemst', label: 'Bekijk / bestel product →' }] };

test('order list shows full quantity and discount, omitting daily dose and usage instructions', () => {
  const tree = exports.ProtocolOrderList({ items: [item] });
  const copy = text(tree);
  assert.ok(copy.includes('5% korting bij Dtails.nl met code: paardentherapeut-5'));
  assert.ok(copy.includes('Heemstwortel'));
  assert.ok(copy.includes('Totaal nodig: 630 g'));
  for (const unwanted of ['15 g', item.instructions, item.description]) assert.ok(!copy.includes(unwanted));
  assert.ok(flatten(tree).findIndex(n => n === '5% korting bij Dtails.nl met code: paardentherapeut-5') < flatten(tree).findIndex(n => n === 'Heemstwortel'));
});

test('purchase button opens exactly the configured product URL', async () => {
  const link = flatten(exports.ProtocolOrderList({ items: [item] })).find(n => n?.props?.accessibilityRole === 'link');
  link.props.onPress();
  assert.equal(opened.at(-1), 'https://example.com/heemst');
  const changed = { ...item, purchaseOptions: [{ url: 'https://another-shop.example/herb' }] };
  flatten(exports.ProtocolOrderList({ items: [changed] })).find(n => n?.props?.accessibilityRole === 'link').props.onPress();
  assert.equal(opened.at(-1), 'https://another-shop.example/herb');
});

test('missing amounts and links are never replaced by daily dosage or invented URLs', () => {
  const tree = exports.ProtocolOrderList({ items: [{ ...item, quantityLabel: null, purchaseOptions: [] }] });
  assert.ok(text(tree).includes('hoeveelheid nog te bepalen'));
  assert.ok(!text(tree).includes('15 g'));
  assert.ok(!flatten(tree).some(n => n?.props?.accessibilityRole === 'link'));
  const unsafe = exports.ProtocolOrderList({ items: [{ ...item, purchaseOptions: [{ url: 'javascript:alert(1)' }] }] });
  assert.ok(!flatten(unsafe).some(n => n?.props?.accessibilityRole === 'link'));
});

test('full-protocol aggregate orders survive cached future phase locks', () => {
  const module = {};
  const js = ts.transpileModule(readFileSync(new URL('../lib/horse-dashboard.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('exports', js)(module);
  const data = { timezone: 'Europe/Amsterdam', protocol: { phases: [], notifications: [], orderItems: [{ ...item, productKey: 'product' }] } };
  assert.deepEqual(module.dashboardAtTime(data, new Date()).protocol.orderItems, data.protocol.orderItems);
});

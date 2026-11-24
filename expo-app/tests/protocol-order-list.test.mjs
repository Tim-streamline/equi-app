import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { protocolStyleModules } from './protocol-styles.mjs';
import * as scope from '../lib/protocol-order-scope.ts';
const jsx = (type, props) => ({ type, props });
const flatten = n => Array.isArray(n) ? n.flatMap(flatten) : n && typeof n === 'object' ? [n, ...flatten(n.props?.children)] : [n];
const text = n => flatten(n).filter(n => typeof n === 'string').join('');
const exports = {}, opened = [];
const code = ts.transpileModule(readFileSync(new URL('../components/protocol/ProtocolOrderList.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;
new Function('require', 'exports', code)(name => ({
  'react/jsx-runtime': { jsx, jsxs: jsx },
  ...protocolStyleModules,
  'lucide-react-native': { Check: 'Check' },
  '@/lib/protocol-order-scope': scope,
  './DoneCircle': { DoneCircle: 'DoneCircle' },
  '@/components/ui/SwitchRow': { SwitchRow: 'SwitchRow' },
  'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable', Linking: { openURL: async url => opened.push(url) }, Alert: { alert() {} } },
})[name], exports);
const item = { id: 'product', name: 'Heemstwortel', quantity: 630, unit: 'g', quantityLabel: '630 g',
  dosage: '15 g', instructions: 'Laat 30 minuten trekken.', description: 'Werking van het product',
  purchaseOptions: [{ url: 'https://example.com/heemst', label: 'Bekijk / bestel product →' }] };

test('order list shows full quantity and discount, omitting daily dose and usage instructions', () => {
  const tree = exports.ProtocolOrderList({ items: [item] });
  const copy = text(tree);
  assert.ok(copy.includes('5% korting bij Dtails.nl met code:'));
  // The code is a text of its own, so it always stays whole on one line.
  const code = flatten(tree).find(n => n?.type === 'Text' && n.props.children === 'paardentherapeut-5');
  assert.ok(code, 'discount code is rendered as its own text');
  assert.equal(code.props.selectable, true);
  assert.ok(copy.includes('Heemstwortel'));
  assert.ok(copy.includes('Totaal nodig: 630 g'));
  for (const unwanted of ['15 g', item.instructions, item.description]) assert.ok(!copy.includes(unwanted));
  assert.ok(flatten(tree).findIndex(n => n === 'paardentherapeut-5') < flatten(tree).findIndex(n => n === 'Heemstwortel'));
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

test('every distinct safe purchase option gets its own distinguishable link', () => {
  const links = tree => flatten(tree).filter(n => n?.props?.accessibilityRole === 'link');
  const options = [{ url: 'https://shop-a.example/herb', label: 'x' }, { url: 'javascript:alert(1)', label: 'x' },
    { url: 'https://www.shop-b.example/herb?ref=1', label: 'x' }, { url: 'https://shop-a.example/herb', label: 'x' }];
  const rendered = links(exports.ProtocolOrderList({ items: [{ ...item, purchaseOptions: options }] }));
  assert.equal(rendered.length, 2);
  assert.deepEqual(rendered.map(link => link.props.accessibilityLabel),
    ['Bekijk / bestel Heemstwortel bij shop-a.example', 'Bekijk / bestel Heemstwortel bij shop-b.example']);
  assert.deepEqual(rendered.map(text), ['Bekijk / bestel product bij shop-a.example →', 'Bekijk / bestel product bij shop-b.example →']);
  rendered[1].props.onPress();
  assert.equal(opened.at(-1), 'https://www.shop-b.example/herb?ref=1');
  const sameShop = links(exports.ProtocolOrderList({ items: [{ ...item, purchaseOptions: [
    { url: 'https://shop-a.example/small', label: 'x' }, { url: 'https://shop-a.example/large', label: 'x' }] }] }));
  assert.equal(new Set(sameShop.map(link => link.props.accessibilityLabel)).size, 2);
  const single = links(exports.ProtocolOrderList({ items: [item] }));
  assert.equal(single[0].props.accessibilityLabel, 'Bekijk / bestel Heemstwortel');
  assert.equal(text(single[0]), 'Bekijk / bestel product →');
});

test('full-protocol aggregate orders survive cached future phase locks', () => {
  const module = {};
  const js = ts.transpileModule(readFileSync(new URL('../lib/horse-dashboard.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('exports', js)(module);
  const data = { timezone: 'Europe/Amsterdam', protocol: { phases: [], notifications: [], orderItems: [{ ...item, productKey: 'product' }] } };
  assert.deepEqual(module.dashboardAtTime(data, new Date()).protocol.orderItems, data.protocol.orderItems);
});

test('each product can be ticked off per phase, and ticking the full list ticks every phase', () => {
  const ordered = { ...item, productKey: 'p', components: [{ selectionId: 's1', phaseId: 'f1' }, { selectionId: 's2', phaseId: 'f2' }] };
  assert.deepEqual(exports.orderKeys(ordered), ['p:f1', 'p:f2']);
  assert.deepEqual(exports.orderKeys({ ...item, productKey: 'p' }), ['p:all']);
  const toggles = [];
  const box = keys => flatten(exports.ProtocolOrderList({ items: [ordered], orderedKeys: keys, onToggle: (k, done) => toggles.push([k, done]) }))
    .find(n => n?.props?.accessibilityRole === 'checkbox');
  assert.equal(box([]).props.accessibilityState.checked, false);
  assert.equal(box(['p:f1']).props.accessibilityState.checked, false, 'one phase ordered is not the whole list');
  assert.equal(box(['p:f1', 'p:f2']).props.accessibilityState.checked, true);
  box(['p:f1']).props.onPress();
  box(['p:f1', 'p:f2']).props.onPress();
  assert.deepEqual(toggles, [[['p:f1', 'p:f2'], true], [['p:f1', 'p:f2'], false]]);
  // Without a handler (the admin preview, older callers) there is nothing to tick.
  assert.ok(!flatten(exports.ProtocolOrderList({ items: [ordered] })).some(n => n?.props?.accessibilityRole === 'checkbox'));
});

test('one gift a day halves twice-daily products only, and the switch appears only when such a product is listed', () => {
  const twice = { ...item, id: 'salt', productKey: 'salt', name: 'Celzout nr. 7', unit: 'tabletten', quantity: 224, quantityLabel: '224 tabletten', calculationComplete: true,
    components: [{ selectionId: 's', phaseId: 'f1', administrationsPerDay: 2, daysPerWeek: 7, administrationsPerWeek: 14, scheduledWeeks: 8, administrations: 112, quantity: 224 }] };
  const once = { ...item, id: 'herb', productKey: 'herb', unit: 'g', quantity: 840, quantityLabel: '840 g', calculationComplete: true,
    components: [{ selectionId: 'h', phaseId: 'f1', administrationsPerDay: 1, daysPerWeek: 7, administrationsPerWeek: 7, scheduledWeeks: 8, administrations: 56, quantity: 840 }] };
  const adjusted = scope.onceDailyOrderItems([twice, once], true);
  assert.equal(adjusted[0].quantityLabel, '112 tabletten');
  assert.equal(adjusted[0].components[0].quantity, 112);
  assert.equal(adjusted[1], once, 'once-daily products are untouched');
  assert.equal(scope.onceDailyOrderItems([twice], false)[0], twice, 'two gifts a day keeps the server quantities');
  // A phase list is summed from the adjusted components.
  assert.equal(scope.scopeOrderItems(adjusted, { phaseId: 'f1' })[0].quantityLabel, '112 tabletten');

  const changes = [];
  const switchFor = items => flatten(exports.ProtocolOrderList({ items, onTwiceDailyChange: value => changes.push(value), twiceDaily: true }))
    .find(n => n?.type === 'SwitchRow');
  const row = switchFor([twice, once]);
  assert.equal(row.props.label, 'Ben je in de gelegenheid om 2x daags te geven?');
  assert.equal(row.props.value, true);
  row.props.onChange(false);
  assert.deepEqual(changes, [false]);
  assert.equal(switchFor([once]), undefined, 'no switch without a twice-daily product');
  assert.equal(flatten(exports.ProtocolOrderList({ items: [twice] })).find(n => n?.type === 'SwitchRow'), undefined, 'no switch without a handler');
});

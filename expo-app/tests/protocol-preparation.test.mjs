import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import { protocolStyleGlobals } from './protocol-styles.mjs';

const source = readFileSync(new URL('../app/(tabs)/(pager)/protocol.tsx', import.meta.url), 'utf8');
const component = source.slice(source.indexOf('function ProtocolPreparation('), source.indexOf('function Today('));
const code = ts.transpileModule(`export ${component}`, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flatten = n => Array.isArray(n) ? n.flatMap(flatten) : n && typeof n === 'object' ? [n, ...flatten(n.props?.children)] : [];
function setup(request = async () => ({}), status = {}) {
  const state = [], ref = { current: false }, requests = [];
  let hook = 0, refreshed = 0, orders = 0;
  const jsx = (type, props) => ({ type, props });
  const deps = {
    ...Object.fromEntries(['View', 'Text', 'Pressable', 'Button', 'Sheet'].map(n => [n, n])),
    useState: initial => { const i = hook++; if (!(i in state)) state[i] = initial; return [state[i], v => { state[i] = v; }]; },
    useRef: () => ref,
    dashboardRequest: async (...args) => { requests.push(args); return request(...args); },
    deviceTimezone: () => 'Europe/Amsterdam',
    ...protocolStyleGlobals,
  };
  const exports = {};
  new Function('require', 'exports', ...Object.keys(deps), code)(() => ({ jsx, jsxs: jsx }), exports, ...Object.values(deps));
  const render = () => { hook = 0; return flatten(exports.ProtocolPreparation({ protocol: { id: 'protocol', ...status }, horseId: 'horse', onOrder: () => orders++, onRefresh: async () => { refreshed++; } })); };
  const button = title => render().find(n => n.type === 'Button' && n.props.title === title);
  return { render, button, requests, refreshed: () => refreshed, orders: () => orders };
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('preparation opens orders and confirms before starting; cancellation never saves a date', () => {
  const app = setup();
  assert.equal(app.render().find(n => n.type === 'Sheet').props.visible, false);
  app.render().find(n => n.props.accessibilityLabel === 'Open de volledige bestellijst').props.onPress();
  assert.equal(app.orders(), 1);
  app.button('Ik start vandaag').props.onPress();
  assert.equal(app.render().find(n => n.type === 'Sheet').props.visible, true);
  assert.equal(app.requests.length, 0);
  app.button('Nog niet').props.onPress();
  assert.equal(app.render().find(n => n.type === 'Sheet').props.visible, false);
  assert.equal(app.requests.length, 0);
  assert.ok(!app.render().some(n => n.props.children === 'Vandaag afvinken'));
});

test('confirmed start saves once even on rapid double taps and refreshes the active protocol', async () => {
  let release;
  const app = setup(() => new Promise(resolve => { release = resolve; }));
  app.button('Ik start vandaag').props.onPress();
  const yes = app.button('Ja, start mijn protocol');
  yes.props.onPress(); yes.props.onPress();
  assert.equal(app.requests.length, 1);
  assert.deepEqual(app.requests[0], ['/api/horses/horse/protocol-start', { protocol_id: 'protocol', confirmed: true, timezone: 'Europe/Amsterdam' }]);
  assert.equal(app.button('Nog niet').props.disabled, true);
  release({}); await settle();
  assert.equal(app.refreshed(), 1);
  assert.equal(app.render().find(n => n.type === 'Sheet').props.visible, false);
});

test('failed start keeps the confirmation open and allows retry', async () => {
  const app = setup(async () => { throw new Error('offline'); });
  app.button('Ik start vandaag').props.onPress();
  app.button('Ja, start mijn protocol').props.onPress(); await settle();
  assert.equal(app.refreshed(), 0);
  assert.equal(app.render().find(n => n.type === 'Sheet').props.visible, true);
  assert.ok(app.render().some(n => n.props.accessibilityRole === 'alert'));
  assert.equal(app.button('Ja, start mijn protocol').props.disabled, false);
});

test('cached preparation keeps the full order list and never activates phases as time passes', () => {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL('../lib/horse-dashboard.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('exports', js)(exports);
  const data = { protocol: { preparing: true, phases: [{ state: 'locked', startsAt: null, statusLabel: 'Nog niet gestart' }], orderItems: [{ id: 'herb' }] } };
  assert.deepEqual(exports.dashboardAtTime(data, new Date('2027-01-01')), data);
});

test('a plan paused or completed before day one cannot be started, but its order list still opens', () => {
  for (const [status, notice] of [[{ paused: true }, 'Je herstelplan is gepauzeerd. Je kunt starten zodra je plan is hervat.'], [{ completed: true }, 'Dit herstelplan is afgerond en kan niet meer worden gestart.']]) {
    const app = setup(undefined, status);
    assert.equal(app.button('Ik start vandaag'), undefined);
    assert.ok(app.render().some(n => n.props.children === notice));
    app.render().find(n => n.props.accessibilityLabel === 'Open de volledige bestellijst').props.onPress();
    assert.equal(app.orders(), 1);
    assert.equal(app.requests.length, 0);
  }
});

test('preparation reads as three steps, and its order link opens the full order list', () => {
  const app = setup();
  const nodes = app.render();
  // Step titles are a prop of the step component, which this shallow render does not expand.
  const texts = [...nodes.filter(n => n.type === 'Text' && typeof n.props.children === 'string').map(n => n.props.children),
    ...nodes.filter(n => typeof n.props?.title === 'string' && typeof n.props?.n === 'number').map(n => n.props.title)];
  for (const text of ['Zo begin je', 'Bestel de producten voor je herstelplan', 'Zorg dat alles in huis is voor fase 1', 'Klaar om te starten?',
    'Klik hieronder op de button. Vandaag wordt dan dag 1 van je herstelplan.', 'Bekijk de volledige bestellijst →']) {
    assert.ok(texts.includes(text), text);
  }
  assert.ok(app.button('Ik start vandaag'));
  // The screen opens the whole list from this link, not only the first phase.
  assert.match(source, /onOrder=\{\(\) => setOrders\('all'\)\}/);
});

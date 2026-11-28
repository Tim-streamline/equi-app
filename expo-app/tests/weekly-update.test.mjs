import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
import * as weekly from '../lib/weekly-update.ts';

const { weeklyUpdateCopy, openWeeklyUpdates, WEEKLY_UPDATE_PLACEHOLDER, WEEKLY_UPDATE_HINT } = weekly;

test('the first horse gets the general line, every next horse says how many are left', () => {
  assert.deepEqual(weeklyUpdateCopy('Moos', 1, 2), { title: 'Hoe gaat het met Moos?', subtitle: 'Ook kleine veranderingen zijn waardevol om vast te leggen.' });
  assert.deepEqual(weeklyUpdateCopy('Bram', 2, 2), { title: 'En hoe gaat het met Bram?', subtitle: 'Nog één paard, dan ben je klaar. Paard 2 van 2.' });
  assert.equal(weeklyUpdateCopy('Bram', 2, 3).subtitle, 'Nog twee paarden, dan ben je klaar. Paard 2 van 3.');
  assert.match(WEEKLY_UPDATE_PLACEHOLDER, /^Wat is je deze week opgevallen\? .*mag je dat aangeven\.$/);
  assert.equal(WEEKLY_UPDATE_HINT, 'Na het opslaan van je weekupdate kun je weer verder in de app.');
});

test('an unexpected response never locks the app', () => {
  assert.deepEqual(openWeeklyUpdates(null), []);
  assert.deepEqual(openWeeklyUpdates({ weeklyUpdates: 'x' }), []);
  const valid = { horseId: 'h', horseName: 'Moos', protocolId: 'p', week: 4 };
  assert.deepEqual(openWeeklyUpdates({ weeklyUpdates: [valid, { ...valid, week: 0 }, { horseId: 'h' }] }), [valid]);
});

const source = await readFile(new URL('../components/protocol/WeeklyUpdateGate.tsx', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(`export ${source.slice(source.indexOf('function AccountWeeklyUpdateGate('))}`, {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
});

function gate(state, server = []) {
  const jsx = (type, props) => ({ type, props });
  const requests = [];
  let hook = 0;
  const dependencies = {
    ...Object.fromEntries(['Modal', 'SafeAreaView', 'KeyboardViewport', 'KeyboardScrollView', 'View', 'Text', 'TextInput', 'Pressable', 'Button'].map(name => [name, name])),
    useDb: () => ({ logout: async () => {} }),
    useState: initial => { const index = hook++; return [index in state ? state[index] : initial, value => { state[index] = typeof value === 'function' ? value(state[index]) : value; }]; },
    useRef: initial => ({ current: initial }), useCallback: fn => fn, useEffect() {},
    AppState: { addEventListener: () => ({ remove() {} }) },
    Alert: { alert: () => assert.fail('Unexpected alert') }, router: { replace() {} },
    dashboardRequest: async (...args) => { requests.push(args); return { weeklyUpdates: server }; }, deviceTimezone: () => 'Europe/Amsterdam',
    accountSession: { revision: 0 }, EYEBROW: 'eyebrow', CHECK_EVERY_MS: 1000,
    ...weekly,
  };
  const exports = {};
  new Function('require', 'exports', ...Object.keys(dependencies), outputText)(() => ({ jsx, jsxs: jsx }), exports, ...Object.values(dependencies));
  return { tree: exports.AccountWeeklyUpdateGate(), requests };
}

const all = (node, match, found = []) => {
  if (!node || typeof node !== 'object') return found;
  if (Array.isArray(node)) { node.forEach(child => all(child, match, found)); return found; }
  if (match(node)) found.push(node);
  all(node.props?.children, match, found);
  return found;
};
const texts = tree => all(tree, node => node.type === 'Text').flatMap(node => [node.props.children].flat()).join('|');
const moos = { horseId: 'h1', horseName: 'Moos', protocolId: 'p1', week: 4 };
const bram = { horseId: 'h2', horseName: 'Bram', protocolId: 'p2', week: 2 };

test('nothing is shown without an open weekly update', () => {
  assert.equal(gate({ 0: [] }).tree, null);
});

test('the weekly update covers the app and cannot be closed, only saved or logged out of', async () => {
  const state = { 0: [moos, bram], 1: 0, 2: { p1: '  Mest steviger.  ' } };
  const { tree, requests } = gate(state, [bram]);
  assert.equal(tree.type, 'Modal');
  assert.equal(tree.props.visible, true);
  assert.equal(tree.props.presentationStyle, 'fullScreen');
  assert.equal(tree.props.onRequestClose(), undefined);
  const shown = texts(tree);
  assert.match(shown, /Hoe gaat het met Moos\?/);
  assert.match(shown, /Ook kleine veranderingen zijn waardevol om vast te leggen\./);
  assert.match(shown, /Na het opslaan van je weekupdate kun je weer verder in de app\./);
  assert.match(shown, /Uitloggen/);
  assert.doesNotMatch(shown, /Annuleren|verplicht|overgeslagen/);
  assert.equal(all(tree, node => node.type === 'Button').length, 1);
  assert.equal(all(tree, node => node.type === 'TextInput')[0].props.placeholder, WEEKLY_UPDATE_PLACEHOLDER);
  const save = all(tree, node => node.type === 'Button')[0];
  assert.equal(save.props.title, 'Weekupdate opslaan');
  save.props.onPress();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(requests.map(([path]) => path), ['/api/horses/h1/weekly-update', '/api/weekly-updates?timezone=Europe%2FAmsterdam']);
  assert.deepEqual(requests[0], ['/api/horses/h1/weekly-update', { protocol_id: 'p1', week: 4, note: 'Mest steviger.', timezone: 'Europe/Amsterdam' }]);
  assert.deepEqual(state[0], [bram]);
  assert.equal(state[1], 1);
  assert.deepEqual(state[2], {});
});

test('a draft stays with its own horse when a refresh moves on to another horse', () => {
  const input = tree => all(tree, node => node.type === 'TextInput')[0];
  const state = { 0: [moos, bram], 1: 0, 2: {} };
  input(gate(state).tree).props.onChangeText('Over Moos');
  assert.deepEqual(state[2], { p1: 'Over Moos' });
  // Moos' update was filled in elsewhere: Bram comes first with an empty field.
  state[0] = [bram];
  const forBram = gate(state).tree;
  assert.equal(input(forBram).props.value, '');
  assert.equal(all(forBram, node => node.type === 'Button')[0].props.disabled, true);
  // The week moved on for the same horse: the text stays.
  state[0] = [{ ...moos, week: 5 }];
  assert.equal(input(gate(state).tree).props.value, 'Over Moos');
});

test('the next horse follows with how many are left', () => {
  const shown = texts(gate({ 0: [bram], 1: 1 }).tree);
  assert.match(shown, /En hoe gaat het met Bram\?/);
  assert.match(shown, /Nog één paard, dan ben je klaar\. Paard 2 van 2\./);
});

test('saving is disabled while the field is empty', () => {
  const save = all(gate({ 0: [moos], 2: { p1: '   ' } }).tree, node => node.type === 'Button')[0];
  assert.equal(save.props.disabled, true);
});

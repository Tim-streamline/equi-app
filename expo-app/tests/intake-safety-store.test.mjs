import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function load(file, modules) {
 const exports = {};
 const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
 }).outputText;
 new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
 return exports;
}
const rules = load('../lib/intake/laminitis.ts', {});
const config = JSON.parse(readFileSync(new URL('../../backend/tests/Fixtures/intake-safety.json', import.meta.url), 'utf8'));

// Exercise the actual store's public context with synced booking/horse rows.
// Keep answers hydrated while local submittedAt is optionally still stale, as
// happens on the render before the booking hydration effect runs.
function context({ answers, episode, submitted_at = null, completed_at = null, localSubmittedAt = null }) {
 const writes = [];
 const recordWrite = (...args) => { writes.push(args); return Promise.resolve(); };
 const db = { execute: recordWrite, writeTransaction: recordWrite };
 const jsx = (type, props) => ({ type, props });
 const react = {
  createContext: () => ({ Provider: 'IntakeContext' }),
  useState: initial => [typeof initial === 'function' ? initial() : initial?.answers ? { ...initial, answers, submittedAt: localSubmittedAt } : initial, () => {}],
  useRef: current => ({ current }), useCallback: fn => fn, useMemo: fn => fn(), useEffect() {},
 };
 const modules = {
  react, 'react/jsx-runtime': { jsx, jsxs: jsx },
  'react-native': { Alert: { alert: recordWrite } },
  'expo-router': { useGlobalSearchParams: () => ({}) },
  '@powersync/react': { useQuery: sql => ({ isLoading: false, data: sql.includes('FROM horses')
   ? [{ laminitis_safety: JSON.stringify(episode) }]
   : sql.includes('FROM intake_bookings') ? [{ id: 'intake-a', submitted_at, completed_at }] : [] }) },
  '@/db/provider': { useDb: () => ({ powersync: db }) },
  '@/db/hooks': { useCurrentUserId: () => 'owner', useCurrentHorseId: () => 'horse' },
  '@/hooks/useHorseDashboard': { dashboardRequest: recordWrite },
  '@/lib/account-session': { accountSession: { revision: 1 } },
  './schema-provider': { useIntakeSchema: () => ({ safetyConfig: config }) },
  './laminitis': rules,
  './persistence': { bookingQuery: 'SELECT * FROM intake_bookings', saveAnswer: recordWrite },
  './submission': {}, './refresh': {}, '../refresh-safety': {},
 };
 const { IntakeProvider } = load('../lib/intake/store.tsx', modules);
 let node = jsx(IntakeProvider, { children: null });
 while (typeof node.type === 'function') node = node.type(node.props);
 assert.equal(node.type, 'IntakeContext');
 return { ...node.props.value, writes };
}

for (const completion of [{ submitted_at:'yesterday' }, { completed_at:'yesterday' }, { localSubmittedAt:'yesterday' }]) {
 test(`store recognizes historical marker after release (${Object.keys(completion)[0]})`, async () => {
  const answers = Object.freeze({ 'custom-safety': Object.freeze({ condition:'urgent', _event:'report-a' }) });
  const before = JSON.stringify(answers);
  const episode = { episode_id:'episode-b', client_marker:'report-b', released_at:'today', configuration:config };
  const store = context({ answers, episode, ...completion });
  assert.equal(store.laminitisBlocked, false);
  assert.equal(store.state.answers['custom-safety']._needs_confirmation, 'closed');
  store.setField('custom-safety', 'condition', 'none');
  store.resetSection('custom-safety'); store.reset();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(store.writes, [], 'submitted answers remain read-only');
  assert.equal(JSON.stringify(answers), before);
 });
}

test('store clears a submitted unresolved report when separate recovery declarations sync', () => {
 const answers = Object.freeze({ 'custom-safety': Object.freeze({ condition:'previous', recovered:'not yet', _event:'report-a' }) });
 const episode = { episode_id:'episode-a', client_marker:'report-a', configuration:config };
 assert.equal(context({ answers, episode, submitted_at:'yesterday' }).laminitisBlocked, true);
 const released = { ...episode, released_at:'today', confirmations:{ resolved:'confirmed', stable:'approved', recovered:'recovered' } };
 const store = context({ answers, episode:released, submitted_at:'yesterday' });
 assert.equal(store.laminitisBlocked, false);
 assert.equal(store.state.answers['custom-safety'].recovered, 'not yet');
 assert.deepEqual(store.writes, []);
 assert.equal(context({ answers, episode:released }).laminitisBlocked, true, 'the same unresolved draft must still block');
});

test('store keeps new active episodes and pending draft reports blocked', () => {
 const answers = { 'custom-safety': { condition:'none', _event:'pending-report' } };
 const episode = { episode_id:'old', client_marker:'old-report', configuration:config };
 assert.equal(context({ answers, episode, submitted_at:'yesterday' }).laminitisBlocked, true);
 assert.equal(context({ answers, episode:null }).laminitisBlocked, true);
 assert.equal(context({ answers, episode:{...episode,released_at:'today'} }).laminitisBlocked, true);
});

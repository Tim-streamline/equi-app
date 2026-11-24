import assert from 'node:assert/strict';
import test from 'node:test';
import { reconcileEvaluation, evaluationComplete, evaluationMoney, horsePossessive } from '../lib/protocol-evaluation.ts';

test('evaluation requires every current question and rejects whitespace-only answers', () => {
  assert.equal(evaluationComplete({ changes: 'Changes', experience: 'Experience' }, { changes: 'Goed' }), false);
  assert.equal(evaluationComplete({ changes: 'Changes' }, { changes: '  ' }), false);
  assert.equal(evaluationComplete({ changes: 'Changes' }, { changes: 'Verbeterd' }), true);
  assert.equal(evaluationComplete({}, {}), false);
});

test('Dutch possessives and discounted price remain readable', () => {
  assert.equal(horsePossessive('Moos'), "Moos'");
  assert.equal(horsePossessive('Alano'), "Alano's");
  assert.match(evaluationMoney(13230), /132,30/);
});

test('a stale draft keeps local edits, includes remote edits and identifies conflicting answers', () => {
  assert.deepEqual(reconcileEvaluation({ a: 'old', b: 'old' }, { a: 'mine', b: 'old', c: 'new' }, { a: 'theirs', b: 'changed' }), {
    answers: { a: 'mine', b: 'changed', c: 'new' }, conflicts: { a: 'theirs' },
  });
});

test('an evaluation stays open through a pending save and conflict refetch', async () => {
  const { readFileSync } = await import('node:fs');
  const { default: ts } = await import('typescript');
  const helpers = await import('../lib/protocol-evaluation.ts');
  const jsx = (type, props) => ({ type, props });
  const state = [], effects = [], stored = { horseName: 'Nova', revision: 0, questions: { a: 'Hoe gaat het?' }, answers: {}, questionVersion: 'v' };
  let cursor = 0, guard, destination, back = 0, fetches = 0, rejectSave, finishRefresh;
  const modules = {
    react: {
      useState(value) { const i = cursor++; if (!(i in state)) state[i] = value;
        return [state[i], next => { state[i] = typeof next === 'function' ? next(state[i]) : next; }]; },
      useRef(value) { const i = cursor++; return state[i] ??= { current: value }; },
      useEffect(fn) { const i = cursor++; if (!state[i]) { state[i] = true; effects.push(fn); } },
    },
    'react/jsx-runtime': { jsx, jsxs: jsx }, 'react-native': { View: 'View', Text: 'Text' },
    '@react-navigation/native': { usePreventRemove: value => { guard = value; } },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { useLocalSearchParams: () => ({ protocolId: 'p' }), router: { canGoBack: () => true, back: () => back++, replace: path => { destination = path; } } },
    '@/components/ui/KeyboardForm': { KeyboardTextInput: 'Input', KeyboardScrollView: 'Scroll', KeyboardViewport: 'Viewport' },
    '@/components/ui/SubHeader': { SubHeader: 'Header' }, '@/components/ui/Button': { Button: 'Button' },
    '@/lib/account-session': { accountSession: { revision: 0 } }, '@/lib/protocol-evaluation': helpers, '@/components/ui/styles': {},
    '@/hooks/useHorseDashboard': { dashboardRequest: (_url, body) => {
      if (body) return new Promise((_, reject) => { rejectSave = reject; });
      if (++fetches === 1) return Promise.resolve(stored);
      return new Promise(resolve => { finishRefresh = resolve; });
    } },
  };
  const code = ts.transpileModule(readFileSync(new URL('../app/protocol-evaluation.tsx', import.meta.url), 'utf8'),
    { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const exports = {};
  new Function('require', 'exports', code)(key => { assert.ok(key in modules, key); return modules[key]; }, exports);
  const form = exports.default();
  const flatten = n => Array.isArray(n) ? n.flatMap(flatten) : n?.props ? [n, ...flatten(n.props.children)] : [];
  const render = () => { cursor = 0; return flatten(form.type(form.props)); };
  render(); effects.shift()(); await Promise.resolve();
  render().find(x => x.type === 'Input').props.onChangeText('Mijn antwoord');
  const nodes = render();
  nodes.find(x => x.props.title === 'Concept opslaan').props.onPress();
  nodes.find(x => x.type === 'Header').props.onBack();
  render(); assert.equal(guard, true); assert.equal(back, 0);
  rejectSave(new Error('(412)')); await Promise.resolve();
  render(); assert.equal(guard, true);
  finishRefresh({ ...stored, submittedAt: '2026-08-22T16:00:00Z', revision: 1, answers: { a: 'Eerder opgeslagen' } }); await Promise.resolve();
  const after = render();
  assert.equal(after.find(x => x.type === 'Input').props.value, 'Mijn antwoord');
  assert.equal(guard, false);
  after.find(x => x.type === 'Header').props.onBack();
  assert.equal(back, 1);
  after.find(x => x.props.children?.props?.children === 'Bekijk de mogelijkheden voor een vervolg →').props.onPress();
  render().find(x => x.props.title === 'Terug naar Home').props.onPress();
  assert.equal(destination, '/(tabs)/(pager)/home');
});

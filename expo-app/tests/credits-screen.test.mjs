import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
import * as credits from '../lib/credits.ts';
const compile = async file => ts.transpileModule(await readFile(new URL(file, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const code = await compile('../app/(tabs)/account/credits.tsx');
const history = await compile('../app/(tabs)/account/credit-history.tsx');
const summary = { balance: 7, monthlyCredits: 7, membershipCap: 28, hasBasic: true, nextRenewal: '2026-10-01T12:00:00Z', endsAt: null, expiring: [], validityMonths: 6, bundles: [], checkoutAvailable: false, history: [] };
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
function screen(data, source = code) {
  const exports = {}, states = [], calls = []; let cursor = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useRef: value => ({ current: value }), useEffect() {}, useState(value) { const i = cursor++; if (!(i in states)) states[i] = value; return [states[i], next => states[i] = typeof next === 'function' ? next(states[i]) : next]; } },
    'react-native': { View: 'View', Text: 'Text', ScrollView: 'ScrollView', Pressable: 'Pressable', ActivityIndicator: 'ActivityIndicator', Platform: { OS: 'android' } },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { useLocalSearchParams: () => ({}), router: { push: path => calls.push(path) } },
    'expo-web-browser': {}, '@/lib/library': {}, '@/lib/credits': credits,
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 76 },
    '@/hooks/useLibraryResource': { useLibraryResource: () => ({ data, refresh: async () => {} }) },
    '@/components/credits/TemporaryCreditButton': { TemporaryCreditButton: 'TemporaryCreditButton' },
    '@/components/ui/SubHeader': { SubHeader: 'SubHeader' }, '@/components/ui/Button': { Button: 'Button' },
  };
  new Function('require', 'exports', source)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const render = () => { cursor = 0; return flatten(exports.default()); };
  return { render, calls, text: () => render().filter(n => typeof n === 'string' || typeof n === 'number').join(' ') };
}
test('credits overview is compact, hides missing bundles and navigates to history', () => {
  const page = screen(summary);
  assert.match(page.text(), /7  credits Beschikbaar/);
  assert.match(page.text(), /Je volgende  7  credits komen op  1 oktober 2026/);
  assert.match(page.text(), /Hoe credits werken/);
  assert.doesNotMatch(page.text(), /Eerstvolgende vervaldatum|niet beschikbaar|Nog geen credittransacties/);
  assert.ok(!page.render().some(n => n?.type === 'TemporaryCreditButton' || n?.props?.title === 'Credits bijkopen'));
  page.render().find(n => n?.props?.accessibilityLabel === 'Creditgeschiedenis').props.onPress();
  assert.deepEqual(page.calls, ['/(tabs)/account/credit-history']);
});
test('cancellation end supersedes renewal date and inactive Basic never advertises new credits', () => {
  const page = screen({ ...summary, endsAt: '2026-10-01T12:00:00Z' });
  assert.match(page.text(), /Je Basic-abonnement loopt tot/);
  assert.doesNotMatch(page.text(), /Je volgende/);
  assert.doesNotMatch(screen({ ...summary, hasBasic: false }).text(), /Je volgende|Je Basic-abonnement loopt tot/);
});
test('active bundles keep the temporary top-up and relevant expiry warning visible', () => {
  const page = screen({ ...summary, bundles: [{ id: 'one', credits: 7 }], expiring: [{ credits: 3, date: '2026-10-14', urgent: false }] });
  assert.match(page.text(), /3\s+credits verlopen\s+op\s+14 oktober 2026/);
  assert.equal(page.render().filter(n => n?.type === 'TemporaryCreditButton').length, 1);
});
test('history uses readable labels and never exposes backend types, reasons, or payment IDs', () => {
  const page = screen({ ...summary, history: [{ id: 'one', type: 'internal_type', description: 'Internal grant reason', reason: 'Internal reason', payment_id: 'payment-secret', amount: 7, created_at: '2026-09-28' }] }, history);
  assert.match(page.text(), /Creditwijziging/);
  assert.doesNotMatch(page.text(), /internal_type|Internal|payment-secret/);
});

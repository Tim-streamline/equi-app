import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const jsx = (type, props) => ({ type, props });
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object'
  ? [node, ...flatten(node.props?.children), ...flatten(node.props?.footer)] : [];
function screen(file, modules) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  new Function('require', 'exports', js)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports.default;
}
function setup() {
  const events = [];
  const section = { id: 'photos', nr: 0, title: 'Intake fotos', fields: [] };
  const modules = {
    react: { useEffect() {}, useState: value => [value, () => {}], useRef: value => ({ current: value }), useMemo: fn => fn() },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { View: 'View', Text: 'Text', ScrollView: 'ScrollView', Pressable: 'Pressable' },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView', useSafeAreaInsets: () => ({ bottom: 0 }) },
    'lucide-react-native': {},
    '@/components/ui/IconButton': { IconButton: 'IconButton' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/components/intake/IntakeScrollView': { IntakeScrollView: 'IntakeScrollView' },
    '@/components/intake/IntakeField': { IntakeField: 'IntakeField' },
    '@/lib/intake/schema-provider': { useIntakeSchema: () => ({ schema: [section] }) },
    '@/lib/intake/store': { useIntake: () => ({ state: { answers: {} }, trackActivity() {} }), formatSavedAgo: () => null },
    '@/lib/intake/logic': { intakeProgress: () => ({done: 0, total: 1, pct: 0}), nextSectionId: () => 'photos',
      answersForSection: () => ({}), answeredCount: () => ({ answered: 0, total: 0 }), isSectionComplete: () => false, isPhotoSection: () => false },
    'expo-router': { useLocalSearchParams: () => ({ id: 'photos' }), router: {
      replace: path => events.push(['replace', path]), dismissTo: path => events.push(['dismissTo', path]),
      back: () => assert.fail('must not walk the nested intake history'),
    } },
  };
  return { events, modules };
}
for (const previous of ['home', 'protocol', null]) test(`overview exits the intake stack with ${previous ?? 'no'} prior app screen`, () => {
  const { events, modules } = setup();
  modules['expo-router'].useNavigation = path => {
    assert.equal(path, '/');
    return { canGoBack: () => previous !== null, goBack: () => events.push(['rootBack', previous]) };
  };
  const tree = screen('../app/intake/overview.tsx', modules)();
  flatten(tree).find(node => node.props.accessibilityLabel === 'Terug naar de app').props.onPress();
  assert.deepEqual(events, previous ? [['rootBack', previous]] : [['replace', '/(tabs)/(pager)/protocol']]);
});
test('every section return dismisses to the existing overview instead of duplicating it', () => {
  const { events, modules } = setup();
  const tree = screen('../app/intake/section/[id].tsx', modules)();
  const controls = flatten(tree).filter(node => node.props.accessibilityLabel === 'Terug naar intakeoverzicht'
    || node.props.title === 'Opslaan en terug naar overzicht');
  assert.equal(controls.length, 3);
  controls.forEach(node => node.props.onPress());
  assert.deepEqual(events, Array.from({ length: 3 }, () => ['dismissTo', '/intake/overview']));
});

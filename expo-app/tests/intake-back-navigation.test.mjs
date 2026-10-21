import * as intakeDates from '../lib/intake/dates.ts';
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
  new Function('require', 'exports', js)(name => { if (name === './dates' || name === '@/lib/intake/dates') return intakeDates; if (name === './MonthYearField') return { MonthYearField: 'MonthYearField' }; assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports.default;
}
function setup() {
  const events = [];
  const section = { id: 'photos', nr: 0, title: 'Foto’s voor je intake', fields: [] };
  const modules = {
    react: { useEffect() {}, useState: value => [value, () => {}], useRef: value => ({ current: value }), useMemo: fn => fn(), useCallback: fn => fn },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { View: 'View', Text: 'Text', ScrollView: 'ScrollView', Pressable: 'Pressable' },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView', useSafeAreaInsets: () => ({ bottom: 0 }) },
    'lucide-react-native': {},
    '@/components/ui/IconButton': { IconButton: 'IconButton' },
    '@/components/intake/LaminitisSafetyNotice': { LaminitisSafetyNotice: 'LaminitisSafetyNotice' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/components/intake/MissingQuestionsModal': { MissingQuestionsModal: 'MissingQuestionsModal' },
    '@/components/intake/IntakeScrollView': { IntakeScrollView: 'IntakeScrollView' },
    '@/components/intake/IntakeField': { IntakeField: 'IntakeField' },
    '@/lib/intake/schema-provider': { useIntakeSchema: () => ({ schema: [section] }) },
    '@/lib/intake/submission': {formatIntakeDate: () => '16 oktober 2026'},
    '@/lib/intake/store': { useIntake: () => ({ state: { answers: {} }, trackActivity() {} }), formatSavedAgo: () => null },
    '@/lib/intake/logic': { intakeProgress: () => ({done: 0, total: 1, pct: 0}), nextSectionId: () => 'photos',
      answersForSection: () => ({}), answeredCount: () => ({ answered: 0, total: 0 }), isSectionComplete: () => false, isPhotoSection: () => false, visibleFieldsForRender: () => [], missingRequired: () => [] },
    'expo-router': { useLocalSearchParams: () => ({ id: 'photos' }), router: {
      replace: path => events.push(['replace', path]), dismissTo: path => events.push(['dismissTo', path]),
      back: () => assert.fail('must not walk the nested intake history'),
    } },
  };
  return { events, modules, section };
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

function questionScreen() {
  const { modules, section } = setup();
  const hooks = [];
  let cursor = 0;
  let effects = [];
  let params = { id: section.id, field: 'first' };
  const calls = [];
  const fields = [{ id: 'first', type: 'text' }, { id: 'second', type: 'text' }];
  const changed = (before, after) => !before || after.some((value, index) => !Object.is(value, before[index]));
  const memo = (fn, deps) => {
    const index = cursor++;
    if (!hooks[index] || changed(hooks[index].deps, deps)) hooks[index] = { deps, value: fn() };
    return hooks[index].value;
  };
  modules.react = {
    useRef(value) {
      const index = cursor++;
      return hooks[index] ??= { current: value };
    },
    useState(value) {
      const index = cursor++;
      hooks[index] ??= { value };
      return [hooks[index].value, next => { hooks[index].value = next; }];
    },
    useMemo: memo,
    useCallback: (fn, deps) => memo(() => fn, deps),
    useEffect(fn, deps) {
      const index = cursor++;
      if (!hooks[index] || changed(hooks[index].deps, deps)) {
        effects.push(() => {
          hooks[index]?.cleanup?.();
          hooks[index] = { deps, cleanup: fn() };
        });
      }
    },
  };
  modules['expo-router'].useLocalSearchParams = () => params;
  modules['@/lib/intake/logic'].visibleFieldsForRender = () => fields;
  modules['@/lib/intake/schema-provider'].useIntakeSchema = () => ({
    schema: [section, { ...section, id: 'other' }],
  });
  const renderScreen = screen('../app/intake/section/[id].tsx', modules);
  let nodes;
  return {
    calls,
    render(next = params) {
      params = next;
      cursor = 0;
      nodes = flatten(renderScreen());
      nodes.find(node => node.type === 'IntakeScrollView').props.scrollRef.current = {
        scrollTo: position => calls.push(position),
      };
    },
    flushEffects() { const pending = effects; effects = []; pending.forEach(fn => fn()); },
    layout(field, y) {
      nodes.find(node => node.props.onLayout && flatten(node.props.children).some(child => child.type === 'IntakeField' && child.props.field.id === field))
        .props.onLayout({ nativeEvent: { layout: { y } } });
    },
    highlighted(field) {
      return nodes.some(node => node.props.onLayout && flatten(node.props.children).some(child => child.type === 'IntakeField' && child.props.field.id === field)
        && node.props.className.includes('border-2'));
    },
  };
}

for (const layoutFirst of [true, false]) test(`question scrolls once with layout ${layoutFirst ? 'before' : 'after'} the opening effect`, t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const page = questionScreen();
  page.render();
  if (layoutFirst) page.layout('first', 300);
  page.flushEffects();
  if (!layoutFirst) page.layout('first', 300);
  assert.deepEqual(page.calls, [{ y: 288, animated: false }]);
  assert.equal(page.highlighted('first'), true);

  // The user scrolls elsewhere while the highlight is visible. Its expiry
  // changes the question's layout but must not move the viewport again.
  t.mock.timers.tick(3000);
  page.render();
  page.flushEffects();
  assert.equal(page.highlighted('first'), false);
  page.layout('first', 284);
  page.layout('first', 320);
  assert.equal(page.calls.length, 1);

  // Opening another question on the mounted screen still scrolls once,
  // including when its position was measured before navigation.
  page.layout('second', 600);
  page.render({ id: 'photos', field: 'second' });
  page.flushEffects();
  page.layout('second', 600);
  assert.deepEqual(page.calls[1], { y: 588, animated: false });
  assert.equal(page.calls.length, 2);

  // A different section must wait for its own layout, not reuse the position
  // of a question with the same ID in the previous section.
  page.render({ id: 'other', field: 'second' });
  page.flushEffects();
  assert.equal(page.calls.length, 2);
  page.layout('second', 100);
  page.layout('second', 100);
  assert.deepEqual(page.calls[2], { y: 88, animated: false });
  assert.equal(page.calls.length, 3);
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

function load(path, modules) {
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const exports = {};
  new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, `Unexpected dependency: ${name}`);
    return modules[name];
  }, exports);
  return exports;
}
const intakeModules = {};
for (const name of ['schema', 'config', 'logic']) intakeModules[`./${name}`] = load(`../lib/intake/${name}.ts`, intakeModules);
const flatten = node => Array.isArray(node) ? node.flatMap(flatten)
  : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
const text = node => flatten(node).filter(value => typeof value === 'string' || typeof value === 'number').join('');

// Render actual Home, shared card and progress logic; mock provider boundaries and native UI.
function screen() {
  const horses = ['plus', 'basic', 'submitted', 'partial'].map(id => ({ id, name: id, status: 'active' }));
  const intakes = Object.fromEntries(horses.map(horse => [horse.id, { loaded: true, state: { answers: {}, submittedAt: null } }]));
  intakes.submitted.state.submittedAt = '2026-09-29T12:00:00Z';
  intakes.partial.state.answers = { paard: { naam: 'Luna' } };
  let selected = 'plus', ready = true, delivered = false;
  const routes = [];
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const modules = {
    '@/constants/brand': { BRAND_NAME: 'EquiApp' },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useState: value => [value, () => {}] },
    'react-native': Object.fromEntries(['View', 'Text', 'ScrollView', 'Pressable', 'Modal', 'ActivityIndicator', 'RefreshControl', 'Alert'].map(name => [name, name])),
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { router: { push: path => routes.push(path) } },
    'lucide-react-native': Object.fromEntries(['ChevronDown', 'Sparkles', 'UserRound', 'ClipboardList', 'ArrowRight', 'Check'].map(name => [name, name])),
    '@/db/hooks': {
      useCurrentUser: () => ({ name: 'Tim' }),
      useHorse: () => horses.find(horse => horse.id === selected) ?? {},
      useHorsesByOwner: () => horses,
      useActiveProtocolForHorse: () => delivered ? { id: 'protocol' } : null,
    },
    '@/db/provider': { useDb: () => ({ selectHorse: id => { selected = id; } }) },
    '@/hooks/useHorseDashboard': { useHorseDashboard: () => ({
      data: ready ? { horse: { id: selected }, greeting: selected ? 'Welkom, Tim' : 'Goedemorgen, Tim', hasPlus: !!selected && selected !== 'basic', recommendations: selected ? [] : [1, 2, 3, 4].map(id => ({ id: `item-${id}`, title: `Item ${id}` })), protocol: null } : null,
      loading: !ready, refresh() {},
    }) },
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 76 },
    '@/hooks/useHomePreferences': { useHomePreferences: () => ({ ready: false }) },
    '@/lib/home-preferences': { seasonalTipVisible: () => false },
    '@/lib/horse-dashboard': {},
    '@/components/home/SeasonalTipCard': { SeasonalTipCard: 'SeasonalTipCard' },
    '@/components/ui/ConnectionStatus': { ConnectionStatus: 'ConnectionStatus' },
    '@/components/library/LibraryCard': { LibraryCard: 'LibraryCard' },
    '@/lib/intake/store': { useIntake: () => intakes[selected] },
    '@/lib/intake/schema-provider': { useIntakeSchema: () => ({ schema: intakeModules['./schema'].INTAKE_SCHEMA, noneOptions: intakeModules['./schema'].INTAKE_NONE_OPTIONS }) },
    '@/lib/intake/logic': intakeModules['./logic'],
  };
  const card = load('../components/intake/IntakeEntryCard.tsx', modules);
  modules['@/components/intake/IntakeEntryCard'] = card;
  const home = load('../app/(tabs)/(pager)/home.tsx', modules);
  const render = () => flatten(home.default());
  const entry = () => render().find(node => node?.type === 'Pressable' && node.props.accessibilityLabel?.includes('intake'));
  const select = id => {
    const row = render().find(node => node?.type === 'Pressable' && node.props.accessibilityState && text(node) === id);
    assert.ok(row, `Horse picker option ${id}`);
    row.props.onPress();
  };
  return { render, entry, select, routes, intakes, card: () => card.IntakeEntryCard({ variant: 'standalone' }),
    set ready(value) { ready = value; }, set delivered(value) { delivered = value; }, clearHorse() { selected = ''; }, withoutHorse() { selected = ''; horses.length = 0; }, addHorse(id) { horses.push({ id, name: id, status: 'active' }); selected = id; },
  };
}

test('Plus Home shows supplied copy between greeting and library and opens the existing intake', () => {
  const app = screen(), tree = app.render(), card = app.entry();
  assert.ok(card);
  assert.ok(tree.indexOf('Welkom, Tim') < tree.indexOf('PROTOCOL INTAKE'));
  assert.ok(tree.indexOf('PROTOCOL INTAKE') < tree.indexOf('Ontdek in de bibliotheek'));
  for (const copy of ['Start jouw protocol intake', '11 korte secties · ongeveer 60 minuten', 'Op basis van deze informatie wordt jouw protocol gemaakt.', 'Start intake →']) assert.ok(text(card).includes(copy), copy);
  assert.ok(!card.props.className.includes('mx-'), 'card aligns with padded Home content');
  card.props.onPress();
  assert.deepEqual(app.routes, ['/intake']);
});

test('partial answers offer continue and submission removes the card', () => {
  const app = screen();
  app.select('partial');
  assert.equal(app.entry().props.accessibilityLabel, 'Ga verder met jouw protocol intake');
  assert.ok(text(app.entry()).includes('Ga verder →'));
  app.entry().props.onPress();
  assert.deepEqual(app.routes, ['/intake']);
  app.intakes.partial.state.submittedAt = '2026-09-29T13:00:00Z';
  assert.equal(app.entry(), undefined);
  assert.ok(app.render().includes('Ontdek in de bibliotheek'));
});

test('horse picker follows each horse’s Plus and intake state', () => {
  const app = screen();
  for (const id of ['basic', 'submitted']) { app.select(id); assert.equal(app.entry(), undefined, id); }
  app.select('partial');
  assert.ok(text(app.entry()).includes('Ga verder →'));
  app.select('plus');
  assert.ok(text(app.entry()).includes('Start intake →'));
  app.clearHorse();
  assert.equal(app.entry(), undefined);
});

test('card waits for dashboard and intake hydration and hides for a delivered protocol', () => {
  const app = screen();
  app.ready = false;
  assert.equal(app.entry(), undefined);
  app.ready = true;
  app.intakes.plus.loaded = false;
  assert.equal(app.entry(), undefined);
  app.intakes.plus.loaded = true;
  assert.ok(app.entry());
  app.delivered = true;
  assert.equal(app.entry(), undefined);
});

test('Protocol standalone retains its copy, route and submission behavior', () => {
  const app = screen();
  assert.ok(text(app.card()).includes('Start jouw protocol intake'));
  assert.ok(!text(app.card()).includes('Start intake →'));
  app.select('partial');
  assert.ok(text(app.card()).includes('Ga verder met je intake'));
  assert.ok(text(app.card()).includes('Verder →'));
  app.card().props.onPress();
  assert.deepEqual(app.routes, ['/intake']);
  app.select('submitted');
  assert.equal(app.card(), null);
});


test('an account without a horse has personal greeting, both add actions, and four library items without protocol tasks', () => {
  const app = screen();
  app.withoutHorse();
  const tree = app.render();
  assert.ok(tree.includes('Goedemorgen, Tim'));
  assert.ok(tree.includes('+ Paard toevoegen'));
  assert.ok(text(tree).includes('Je paard toevoegen aan EquiApp?'));
  assert.ok(tree.includes('Voeg je paard toe aan je account, zodat je alle gegevens op één plek kunt bewaren.'));
  assert.equal(tree.filter(node => node?.type === 'LibraryCard').length, 4);
  assert.ok(tree.includes('Ontdek in de bibliotheek'));
  assert.equal(app.entry(), undefined);
  assert.ok(!tree.some(node => node?.props?.accessibilityLabel === 'Wissel paard'));
  for (const copy of ['+ Paard toevoegen', 'Paard toevoegen →']) {
    const action = tree.find(node => node?.type === 'Pressable' && text(node) === copy);
    assert.ok(action);
    action.props.onPress();
  }
  assert.deepEqual(app.routes, ['/onboarding/add-horse', '/onboarding/add-horse']);
  app.addHorse('basic');
  assert.ok(app.render().some(node => node?.props?.accessibilityLabel === 'Wissel paard'));
  assert.ok(!text(app.render()).includes('Je paard toevoegen aan EquiApp?'));
  assert.equal(app.entry(), undefined);
  app.addHorse('plus');
  assert.ok(app.entry(), 'Adding a Plus horse immediately uses its intake variant');
});

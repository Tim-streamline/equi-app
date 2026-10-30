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

const flatten = node => Array.isArray(node) ? node.flatMap(flatten)
  : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
const text = node => flatten(node).filter(value => typeof value === 'string' || typeof value === 'number').join('');

// Render actual Home, shared card and progress logic; mock provider boundaries and native UI.
function screen(homeUpsell) {
  const horses = ['purchased', 'unpurchased', 'submitted', 'partial'].map(id => ({ id, name: id, status: 'active' }));
  const intakes = Object.fromEntries(horses.map(horse => [horse.id, { loaded: true, state: { answers: {}, submittedAt: null } }]));
  intakes.submitted.state.submittedAt = '2026-09-29T12:00:00Z';
  intakes.partial.state.answers = { paard: { naam: 'Luna' } };
  let selected = 'purchased', ready = true, delivered = false;
  const routes = [];
  const purchases = { purchased: [{ id: 'p1', title: 'Protocol Nova', status: 'draft', booking_id: 'i1', submitted_at: null }], unpurchased: [], submitted: [{ id: 'p2', title: 'Protocol Luna', status: 'draft', booking_id: 'i2', submitted_at: '2026-10-01' }], partial: [{ id: 'p3', title: 'Vervolgprotocol', status: 'draft', booking_id: 'i3', submitted_at: null }] };
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const modules = {
    '@/constants/brand': { BRAND_NAME: 'EquiApp' },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useEffect: () => {}, useState: value => [value, () => {}] },
    'react-native': Object.fromEntries(['View', 'Text', 'ScrollView', 'Pressable', 'Modal', 'ActivityIndicator', 'RefreshControl', 'Alert'].map(name => [name, name])),
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { useLocalSearchParams: () => ({}), router: { push: path => routes.push(path) } },
    'lucide-react-native': Object.fromEntries(['ChevronDown', 'Sparkles', 'UserRound', 'ClipboardList', 'ArrowRight', 'Check'].map(name => [name, name])),
    '@/db/hooks': {
      useCurrentHorseId: () => selected, useCurrentUserId: () => 'u',
      useCurrentUser: () => ({ name: 'Tim' }),
      useHorse: () => horses.find(horse => horse.id === selected) ?? {},
      useHorsesByOwner: () => horses,
      useActiveProtocolForHorse: () => delivered ? { id: 'protocol' } : null,
    },
    '@powersync/react': { useQuery: (_sql, [horse, user]) => { assert.equal(user, 'u'); return { data: purchases[horse] ?? [] }; } },
    '@/components/ui/Button': { Button: 'Button', PRIMARY_INK: '#083635' },
    '@/db/provider': { useDb: () => ({ selectHorse: id => { selected = id; } }) },
    '@/hooks/useHorseDashboard': { useHorseDashboard: () => ({
      data: ready ? { horse: { id: selected }, greeting: selected ? 'Welkom, Tim' : 'Goedemorgen, Tim', hasPlus: !!selected && selected !== 'basic', showPlusUpsell: selected === 'unpurchased', homeUpsell, recommendations: selected ? [] : [1, 2, 3, 4].map(id => ({ id: `item-${id}`, title: `Item ${id}` })), protocol: null } : null,
      loading: !ready, refresh() {},
    }) },
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 76 },
    '@/hooks/useHomePreferences': { useHomePreferences: () => ({ ready: false }) },
    '@/lib/home-preferences': { seasonalTipVisible: () => false },
    '@/lib/horse-dashboard': {},
    '@/components/home/SeasonalTipCard': { SeasonalTipCard: 'SeasonalTipCard' },
    '@/components/ui/ConnectionStatus': { ConnectionStatus: 'ConnectionStatus' },
    '@/components/library/LibraryCard': { LibraryCard: 'LibraryCard' },
    '@/lib/intake/submission': {formatIntakeDate: () => '1 oktober 2026'},
    '@/lib/intake/store': { useIntake: () => intakes[selected] },
    '@/components/shelby/ShelbyChatButton': { ShelbyChatButton: 'ShelbyChatButton' },
  };
  modules['./LaminitisSafetyNotice'] = { LaminitisSafetyNotice: () => null };
  modules['@/components/intake/PurchasedProtocols'] = load('../components/intake/PurchasedProtocols.tsx', modules);
  const home = load('../app/(tabs)/(pager)/home.tsx', modules);
  const render = () => flatten(home.default());
  const entry = () => render().find(node => node?.type === 'Button' && node.props.title === 'Intake openen');
  const select = id => {
    const row = render().find(node => node?.type === 'Pressable' && node.props.accessibilityState && text(node) === id);
    assert.ok(row, `Horse picker option ${id}`);
    row.props.onPress();
  };
  return { render, entry, select, routes, intakes, purchases,
    set ready(value) { ready = value; }, set delivered(value) { delivered = value; }, clearHorse() { selected = ''; }, withoutHorse() { selected = ''; horses.length = 0; }, addHorse(id) { horses.push({ id, name: id, status: 'active' }); selected = id; },
  };
}

test('Home renders the admin promotion copy and keeps its purchase destination', () => {
  const copy = { title: 'Eigen kop', body: 'Eigen omschrijving', button: 'Bekijk mijn traject' };
  const app = screen(copy);
  app.select('unpurchased');
  const tree = app.render();
  for (const value of Object.values(copy)) assert.ok(tree.includes(value));
  const card = tree.find(node => node?.type === 'Pressable' && node.props.accessibilityRole === 'link' && text(node).includes(copy.button));
  card.props.onPress();
  assert.equal(app.routes.at(-1), '/protocol-purchase');
});

test('Home keeps readable promotion text with older cached dashboard data', () => {
  const app = screen();
  app.select('unpurchased');
  const tree = app.render();
  assert.ok(tree.includes('Twijfel je wat jouw paard écht nodig heeft?'));
  assert.ok(tree.includes('Ontdek EquiBoost'));
});

test('Home lists the purchased protocol and opens its exact intake', () => {
  const app = screen(), tree = app.render();
  assert.ok(tree.includes('Protocol Nova'));
  assert.ok(tree.indexOf('Welkom, Tim') < tree.indexOf('Protocol Nova'));
  assert.ok(tree.indexOf('Protocol Nova') < tree.indexOf('Ontdek in de bibliotheek'));
  app.entry().props.onPress();
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'i1' } }]);
});
test('selected horse determines purchased intakes, including when dashboard is offline', () => {
  const app = screen(); app.select('unpurchased'); assert.equal(app.entry(), undefined);
  app.select('partial'); app.ready = false; assert.ok(app.entry());
  app.entry().props.onPress();
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'i3' } }]);
  app.select('submitted'); assert.equal(app.entry(), undefined);
  assert.ok(text(app.render()).includes('Je intake is verstuurd.'));
  assert.ok(text(app.render()).includes('Intake ingestuurd op 1 oktober 2026'));
  app.render().find(n => n?.type === 'Button' && n.props.title === 'Intake bekijken').props.onPress();
  assert.deepEqual(app.routes.at(-1), {pathname: '/intake', params: {bookingId: 'i2'}});
});
test('finished protocols stay visible alongside a newly purchased intake', () => {
  const app = screen();
  app.purchases.purchased.push({ id: 'old', title: 'Vorig protocol', status: 'completed', booking_id: 'old-intake', submitted_at: null });
  assert.ok(app.render().includes('Vorig protocol')); assert.ok(app.render().includes('Afgerond'));
  assert.equal(app.render().filter(n => n?.type === 'Button' && n.props.title === 'Intake openen').length, 1);
});

test('an account without a horse has personal greeting, one add action, and four library items without protocol tasks', () => {
  const app = screen();
  app.withoutHorse();
  const tree = app.render();
  assert.ok(tree.includes('Goedemorgen, Tim'));
  assert.ok(!tree.some(node => node?.props?.accessibilityLabel === 'Paard toevoegen'), 'no header pill: the card below already offers adding a horse');
  assert.ok(text(tree).includes('Je paard toevoegen aan EquiApp?'));
  assert.ok(tree.includes('Voeg je paard toe aan je account, zodat je alle gegevens op één plek kunt bewaren.'));
  assert.equal(tree.filter(node => node?.type === 'LibraryCard').length, 4);
  assert.ok(tree.includes('Ontdek in de bibliotheek'));
  assert.equal(app.entry(), undefined);
  assert.ok(!tree.some(node => node?.props?.accessibilityLabel === 'Wissel paard'));
  const actions = tree.filter(node => node?.type === 'Button' && node.props.title === 'Paard toevoegen');
  assert.equal(actions.length, 1);
  actions[0].props.onPress();
  assert.deepEqual(app.routes, ['/onboarding/add-horse']);
  app.addHorse('unpurchased');
  assert.ok(app.render().some(node => node?.props?.accessibilityLabel === 'Wissel paard'));
  assert.ok(!text(app.render()).includes('Je paard toevoegen aan EquiApp?'));
  assert.equal(app.entry(), undefined);
  app.addHorse('purchased');
  assert.ok(app.entry(), 'Switching to a horse shows its purchased intake');
});

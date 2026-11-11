import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as uiStyles from '../components/ui/styles.ts';

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
  const activeProtocols = [], preparingProtocols = [];
  let oldCachedResponse = false;
  const routes = [];
  const purchases = { purchased: [{ id: 'p1', title: 'Het EquiBoost traject van purchased', status: 'draft', booking_id: 'i1', submitted_at: null }], unpurchased: [], submitted: [{ id: 'p2', title: 'Het EquiBoost traject van submitted', status: 'draft', booking_id: 'i2', submitted_at: '2026-10-01' }], partial: [{ id: 'p3', title: 'Het EquiBoost traject van partial', status: 'draft', booking_id: 'i3', submitted_at: null }] };
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
    // Home asks for every active horse (only the user id); Protocol for the selected horse.
    '@powersync/react': { useQuery: (sql, params) => {
      assert.equal(params.at(-1), 'u');
      const allHorses = sql === 'all-horses';
      const rows = (allHorses ? horses : horses.filter(item => item.id === params[0])).flatMap(item => (purchases[item.id] ?? []).map(row => ({ ...row, horse_id: item.id, horse_name: item.name })));
      return { data: rows };
    } },
    '@/components/ui/Button': { Button: 'Button', PRIMARY_INK: '#083635' },
    '@/components/ui/styles': uiStyles,
    '@/db/provider': { useDb: () => ({ selectHorse: id => { selected = id; } }) },
    '@/hooks/useHorseDashboard': { useHorseDashboard: () => ({
      data: ready ? { horse: { id: selected }, greeting: selected ? 'Welkom, Tim' : 'Goedemorgen, Tim', hasPlus: !!selected && selected !== 'basic', showPlusUpsell: selected === 'unpurchased', homeUpsell, recommendations: selected ? [] : [1, 2, 3, 4].map(id => ({ id: `item-${id}`, title: `Item ${id}` })), protocol: activeProtocols.includes(selected) ? { id: `p-${selected}`, statusLabel: `Actief voor ${selected}`, currentDay: preparingProtocols.includes(selected) ? 0 : 3, currentWeek: preparingProtocols.includes(selected) ? 0 : 1, progressPercent: 10 } : null,
        // The server's per-horse summaries, sorted by name (older cached responses lack them).
        ...(oldCachedResponse ? {} : { horseProtocols: horses.filter(horse => activeProtocols.includes(horse.id)).sort((a, b) => a.name.localeCompare(b.name))
          .map(horse => ({ horseId: horse.id, horseName: horse.name, id: `p-${horse.id}`, statusLabel: `Actief voor ${horse.id}`, currentDay: preparingProtocols.includes(horse.id) ? 0 : 10, currentWeek: preparingProtocols.includes(horse.id) ? 0 : 2, progressPercent: 36 })) }) } : null,
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
  modules['@/components/ui/OfflineStrip'] = { OfflineStrip: 'OfflineStrip' };
  modules['@/lib/intake/protocol-queries'] = { PROTOCOLS_FOR_HORSE: 'for-horse', PROTOCOLS_FOR_ALL_HORSES: 'all-horses' };
  modules['@/components/intake/PurchasedProtocols'] = load('../components/intake/PurchasedProtocols.tsx', modules);
  const home = load('../app/(tabs)/(pager)/home.tsx', modules);
  const render = () => flatten(home.default());
  const entry = () => render().find(node => node?.type === 'Button' && node.props.title === 'Intake openen');
  // The selected horse is chosen elsewhere (Protocol); Home has no selector.
  const select = id => { selected = id; };
  return { render, entry, select, routes, intakes, purchases, activeProtocols, preparingProtocols, selected: () => selected,
    set ready(value) { ready = value; }, set oldCachedResponse(value) { oldCachedResponse = value; }, set delivered(value) { delivered = value; }, clearHorse() { selected = ''; }, withoutHorse() { selected = ''; horses.length = 0; }, addHorse(id) { horses.push({ id, name: id, status: 'active' }); selected = id; },
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
  assert.ok(tree.includes('Het EquiBoost traject van purchased'));
  assert.ok(tree.indexOf('Welkom, Tim') < tree.indexOf('Het EquiBoost traject van purchased'));
  assert.ok(tree.indexOf('Het EquiBoost traject van purchased') < tree.indexOf('Ontdek in de bibliotheek'));
  app.entry().props.onPress();
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'i1' } }]);
});
test('Home shows every horse\'s intake as its own card, whichever horse is selected and also offline', () => {
  const app = screen();
  const open = () => app.render().filter(n => n?.type === 'Button' && n.props.title === 'Intake openen');
  for (const horse of ['unpurchased', 'partial']) {
    app.select(horse); app.ready = false;
    assert.equal(open().length, 2, 'Nova and the follow-up intake each have a card');
  }
  const tree = app.render();
  for (const title of ['Het EquiBoost traject van purchased', 'Het EquiBoost traject van submitted', 'Het EquiBoost traject van partial']) assert.ok(tree.includes(title));
  assert.ok(tree.includes('Het EquiBoost traject van partial'), 'the trajectory identifies its horse');
  open()[1].props.onPress();
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'i3' } }]);
  assert.ok(text(app.render()).includes('Je intake is verstuurd.'));
  assert.ok(text(app.render()).includes('Intake ingestuurd op 1 oktober 2026'));
  app.render().find(n => n?.type === 'Button' && n.props.title === 'Intake bekijken').props.onPress();
  assert.deepEqual(app.routes.at(-1), {pathname: '/intake', params: {bookingId: 'i2'}});
});
test('finished protocols stay visible alongside a newly purchased intake', () => {
  const app = screen();
  app.purchases.purchased.push({ id: 'old', title: 'Vorig protocol', status: 'completed', booking_id: 'old-intake', submitted_at: null });
  assert.ok(app.render().includes('Vorig protocol')); assert.ok(app.render().includes('Afgerond'));
  assert.equal(app.render().filter(n => n?.type === 'Button' && n.props.title === 'Intake openen').length, 2, 'only the two open intakes, not the finished protocol');
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
  assert.ok(!app.render().some(node => node?.props?.accessibilityLabel === 'Wissel paard'), 'Home has no horse selector');
  assert.ok(!text(app.render()).includes('Je paard toevoegen aan EquiApp?'));
  assert.equal(app.entry(), undefined);
  app.addHorse('purchased');
  assert.ok(app.entry(), 'a newly added horse with a purchased intake shows its card');
});

test('an intake card of another horse selects that horse before opening its intake', () => {
  const app = screen();
  app.select('unpurchased');
  const partial = app.render().filter(n => n?.type === 'Button' && n.props.title === 'Intake openen')[1];
  partial.props.onPress();
  assert.equal(app.selected(), 'partial', 'the intake looks up its booking under the selected horse');
  assert.deepEqual(app.routes.at(-1), { pathname: '/intake', params: { bookingId: 'i3' } });
});

test('Home shows an active protocol card for every horse that has one, with one dashboard request', () => {
  const app = screen();
  app.activeProtocols.push('purchased', 'partial');
  app.select('purchased');
  const tree = app.render();
  const cards = tree.filter(n => n?.type === 'Pressable' && text(n).includes("'s protocol"));
  assert.deepEqual(cards.map(card => text(card).split("'s protocol")[0]), ['partial', 'purchased'], 'one card per horse, in the server order');
  assert.ok(text(cards[0]).includes('Actief voor partial') && text(cards[0]).includes('Dag 10 · Week 2'), 'every card uses its server summary');
  cards[0].props.onPress();
  assert.equal(app.selected(), 'partial', 'Protocol opens on the horse of the card');
  assert.equal(app.routes.at(-1).pathname, '/(tabs)/(pager)/protocol');
});

test('an older cached dashboard without per-horse summaries still shows the selected horse\'s protocol', () => {
  const app = screen();
  app.activeProtocols.push('partial', 'purchased');
  app.select('partial');
  app.oldCachedResponse = true;
  const cards = app.render().filter(n => n?.type === 'Pressable' && text(n).includes('Ga verder met het protocol'));
  assert.equal(cards.length, 1);
  assert.ok(text(cards[0]).includes('Actief voor partial'));
});

test('a published plan without a protocol card says it is ready, while active drafts still say it is being written', () => {
  const app = screen();
  // No dashboard card (e.g. offline or no response yet): the local query decides.
  app.purchases.submitted[0] = { ...app.purchases.submitted[0], title: 'Persoonlijk protocol', status: 'active', published: 1 };
  let copy = text(app.render());
  assert.ok(copy.includes('Herstelplan van submitted'));
  assert.ok(copy.includes('Je herstelplan staat klaar.'));
  assert.ok(!copy.includes('Je behandelaar stelt je herstelplan op.'));
  assert.ok(app.render().some(n => n?.type === 'Button' && n.props.title === 'Intake bekijken'));
  app.purchases.submitted[0] = { ...app.purchases.submitted[0], status: 'paused' };
  assert.ok(text(app.render()).includes('Je herstelplan is gepauzeerd.'));
  // Unpublished (or not yet published) plans can be active drafts: keep the writing copy.
  for (const row of [{ status: 'active', published: 0 }, { status: 'draft', published: 1 }]) {
    app.purchases.submitted[0] = { ...app.purchases.submitted[0], ...row };
    copy = text(app.render());
    assert.ok(copy.includes('Je intake is verstuurd. Je behandelaar stelt je herstelplan op.'));
    assert.ok(!copy.includes('Je herstelplan staat klaar.'));
  }
  // A dashboard card still replaces the fallback card for that exact protocol.
  app.purchases.submitted[0] = { ...app.purchases.submitted[0], id: 'p-submitted', status: 'active', published: 1 };
  app.activeProtocols.push('submitted');
  assert.ok(!text(app.render()).includes('Je herstelplan staat klaar.'));
});

test('the local intake query derives publication like the dashboard, ignoring future dates', async () => {
  const { DatabaseSync } = await import('node:sqlite');
  const queries = await import('../lib/intake/protocol-queries.ts');
  const db = new DatabaseSync(':memory:');
  db.exec(`CREATE TABLE protocols (id TEXT, title TEXT, status TEXT, horse_id TEXT, published_at TEXT, created_at TEXT);
    CREATE TABLE intake_bookings (id TEXT, protocol_id TEXT, user_id TEXT, status TEXT, submitted_at TEXT, completed_at TEXT);
    CREATE TABLE horses (id TEXT, name TEXT, status TEXT);
    INSERT INTO horses VALUES ('h', 'Nova', 'active');`);
  const rows = [['past', '2026-01-01 10:00:00.000Z'], ['future', '2999-01-01T10:00:00+00:00'], ['draft', null]];
  rows.forEach(([id, published], index) => {
    db.prepare("INSERT INTO protocols VALUES (?, 'Plan', 'active', 'h', ?, ?)").run(id, published, `2026-01-0${index + 1}`);
    db.prepare("INSERT INTO intake_bookings VALUES (?, ?, 'u', 'pending', '2026-01-01', NULL)").run(`i-${id}`, id);
  });
  const published = sql => Object.fromEntries(sql.map(row => [row.id, row.published]));
  const expected = { past: 1, future: 0, draft: 0 };
  assert.deepEqual(published(db.prepare(queries.PROTOCOLS_FOR_HORSE).all('h', 'u')), expected);
  assert.deepEqual(published(db.prepare(queries.PROTOCOLS_FOR_ALL_HORSES).all('u')), expected);
});

test('Home removes only the intake card whose published protocol already has a protocol card', () => {
  const app = screen();
  app.activeProtocols.push('submitted');
  app.purchases.submitted[0].id = 'p-submitted';
  const tree = app.render();
  assert.ok(!tree.some(n => n?.type === 'Button' && n.props.title === 'Intake bekijken'));
  assert.equal(tree.filter(n => n?.type === 'Button' && n.props.title === 'Intake openen').length, 2);
  assert.ok(text(tree).includes("submitted's protocol"));
  // A second purchase for the same horse is a different intake and remains available.
  app.purchases.submitted.push({ id: 'new-purchase', title: 'Nieuw protocol', status: 'draft', booking_id: 'new-intake' });
  assert.equal(app.render().filter(n => n?.type === 'Button' && n.props.title === 'Intake openen').length, 3);
});

test('an unstarted Home protocol opens that horse\'s order list and shows no day, week, phase or progress', () => {
  const app = screen();
  app.activeProtocols.push('submitted');
  app.preparingProtocols.push('submitted');
  app.purchases.submitted[0].id = 'p-submitted';
  const cards = () => app.render().filter(n => n?.type === 'Pressable' && n.props.accessibilityLabel?.startsWith('Protocol van '));
  const card = cards()[0];
  assert.ok(text(card).includes('Je herstelplan voor submitted staat klaar'));
  assert.ok(text(card).includes('Controleer wat je al in huis hebt en welke kruiden of andere producten je nog moet bestellen, zodat je zo snel mogelijk van start kan gaan.'));
  assert.ok(!text(card).includes('Dag ') && !text(card).includes('Week ') && !text(card).includes('Actief voor'));
  assert.ok(!flatten(card).some(n => n?.props?.style?.width));
  card.props.onPress();
  assert.equal(app.selected(), 'submitted');
  assert.equal(app.routes.at(-1).params.horseId, 'submitted');
  assert.equal(app.routes.at(-1).params.protocolId, 'p-submitted');
  assert.equal(app.routes.at(-1).params.orderList, '1');
  app.preparingProtocols.length = 0;
  assert.ok(text(cards()[0]).includes('Actief voor submitted'));
  assert.ok(text(cards()[0]).includes('Dag 10 · Week 2'));
});

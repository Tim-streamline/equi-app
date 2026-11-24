import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as uiStyles from '../components/ui/styles.ts';
import * as evaluationHelpers from '../lib/protocol-evaluation.ts';

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
  const activeProtocols = [], preparingProtocols = [], evaluatingProtocols = [];
  // Fields of one horse's server summary that a test sets itself.
  const summaries = {};
  let oldCachedResponse = false;
  const routes = [];
  const purchases = { purchased: [{ id: 'p1', title: "purchased's intake", status: 'draft', booking_id: 'i1', submitted_at: null }], unpurchased: [], submitted: [{ id: 'p2', title: "submitted's intake", status: 'draft', booking_id: 'i2', submitted_at: '2026-10-01' }], partial: [{ id: 'p3', title: "partial's intake", status: 'draft', booking_id: 'i3', submitted_at: null }] };
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
    '@/lib/protocol-evaluation': evaluationHelpers,
    '@/db/provider': { useDb: () => ({ selectHorse: id => { selected = id; } }) },
    '@/hooks/useHorseDashboard': { useHorseDashboard: () => ({
      data: ready ? { horse: { id: selected, name: selected }, greeting: selected ? 'Welkom, Tim' : 'Goedemorgen, Tim', hasPlus: !!selected && selected !== 'basic', showPlusUpsell: selected === 'unpurchased', homeUpsell, recommendations: selected ? [] : [1, 2, 3, 4].map(id => ({ id: `item-${id}`, title: `Item ${id}` })), protocol: activeProtocols.includes(selected) ? { id: `p-${selected}`, statusLabel: `Actief voor ${selected}`, currentDay: preparingProtocols.includes(selected) ? 0 : 3, currentWeek: preparingProtocols.includes(selected) ? 0 : 1, progressPercent: 10 } : null,
        // The server's per-horse summaries, sorted by name (older cached responses lack them).
        ...(oldCachedResponse ? {} : { horseProtocols: horses.filter(horse => activeProtocols.includes(horse.id)).sort((a, b) => a.name.localeCompare(b.name))
          .map(horse => ({ horseId: horse.id, horseName: horse.name, id: `p-${horse.id}`, statusLabel: `Actief voor ${horse.id}`, currentDay: preparingProtocols.includes(horse.id) ? 0 : 10, currentWeek: preparingProtocols.includes(horse.id) ? 0 : 2, progressPercent: 36, evaluation: evaluatingProtocols.includes(horse.id) ? { available: true, daysRemaining: 14 } : undefined, ...summaries[horse.id] })) }) } : null,
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
  const entry = () => render().find(node => node?.type === 'Pressable' && /^(Start de intake|Ga verder met de intake) voor /.test(node.props.accessibilityLabel || ''));
  // The selected horse is chosen elsewhere (Protocol); Home has no selector.
  const select = id => { selected = id; };
  return { render, entry, select, routes, intakes, purchases, activeProtocols, preparingProtocols, evaluatingProtocols, summaries, selected: () => selected,
    set ready(value) { ready = value; }, set oldCachedResponse(value) { oldCachedResponse = value; }, set delivered(value) { delivered = value; }, clearHorse() { selected = ''; }, withoutHorse() { selected = ''; horses.length = 0; }, addHorse(id) { horses.push({ id, name: id, status: 'active' }); selected = id; },
  };
}

test('evaluation has top priority while the active protocol stays independently accessible', () => {
  const app = screen();
  app.addHorse('Moos');
  app.activeProtocols.push('Moos');
  app.evaluatingProtocols.push('Moos');
  const tree = app.render();
  const reminder = tree.find(n => n?.props?.accessibilityLabel === 'Evalueer de gezondheid van Moos');
  const active = tree.find(n => n?.props?.accessibilityLabel === 'Protocol van Moos');
  assert.ok(reminder && active);
  assert.ok(tree.indexOf(reminder) < tree.findIndex(n => n?.props?.accessibilityLabel === 'Start de intake voor purchased'));
  assert.ok(text(reminder).includes("Moos' huidige EquiBoost traject is bijna afgerond!"));
  assert.ok(!text(reminder).includes("Moos's"));
  reminder.props.onPress();
  assert.equal(app.routes.at(-1).pathname, '/protocol-evaluation');
  active.props.onPress();
  assert.equal(app.routes.at(-1).pathname, '/(tabs)/(pager)/protocol');
});

test('a sent evaluation is offered for review, not asked for again', () => {
  const app = screen();
  app.addHorse('Moos');
  app.activeProtocols.push('Moos');
  app.summaries.Moos = { evaluation: { available: true, daysRemaining: -40, submitted: true } };
  const tree = app.render();
  assert.ok(!tree.some(n => n?.props?.accessibilityLabel === 'Evalueer de gezondheid van Moos'));
  const sent = tree.find(n => n?.props?.accessibilityLabel === 'Bekijk of wijzig de evaluatie van Moos');
  assert.ok(text(sent).includes('Je evaluatie is verstuurd'));
  assert.ok(text(sent).includes('Bekijk of wijzig je evaluatie →'));
  assert.ok(!text(sent).includes('hét moment'));
  sent.props.onPress();
  assert.equal(app.routes.at(-1).pathname, '/protocol-evaluation');
});

test('a plan closed before its scheduled end is not announced as almost finished', () => {
  const app = screen();
  app.addHorse('Moos');
  app.activeProtocols.push('Moos');
  app.summaries.Moos = { statusLabel: 'Je protocol is afgerond', evaluation: { available: true, daysRemaining: 9, completed: true } };
  const reminder = app.render().find(n => n?.props?.accessibilityLabel === 'Evalueer de gezondheid van Moos');
  assert.ok(text(reminder).includes("Moos' huidige EquiBoost traject is afgerond!"));
  assert.ok(text(reminder).includes("Inmiddels is Moos' huidige herstelplan afgelopen."));
  assert.ok(!text(reminder).includes('bijna') && !text(reminder).includes('Over 9 dagen'));
});

test('a plan completed before the owner started opens read-only without an order prompt or day zero', () => {
  const app = screen();
  app.addHorse('Moos');
  app.activeProtocols.push('Moos');
  app.summaries.Moos = { statusLabel: 'Je protocol is afgerond', preparing: true, currentDay: 0, currentWeek: 0 };
  const card = app.render().find(node => node?.props?.accessibilityLabel === 'Protocol van Moos');
  assert.match(text(card), /Je protocol is afgerond/);
  assert.doesNotMatch(text(card), /Bestel|Dag 0|Week 0/);
  card.props.onPress();
  assert.equal(app.routes.at(-1).params.orderList, '0');
});

test('a plan with a start date still ahead says when it starts and offers nothing to tick off', () => {
  const app = screen();
  app.addHorse('Moos');
  app.activeProtocols.push('Moos');
  app.summaries.Moos = { statusLabel: 'Je protocol start binnenkort', preparing: false, currentDay: 0, currentWeek: 0, progressPercent: 0, startedAt: '2026-11-23' };
  const card = app.render().find(n => n?.props?.accessibilityLabel === 'Protocol van Moos');
  assert.ok(text(card).includes("Moos' herstelplan start op 23 november"));
  assert.ok(text(card).includes('Bekijk je herstelplan →'));
  for (const wrong of ['in volle gang', 'Dag 0', 'Vink af']) assert.ok(!text(card).includes(wrong), wrong);
  // Once running, the same card counts days again.
  app.summaries.Moos = { statusLabel: 'Je protocol is actief', preparing: false, currentDay: 3, currentWeek: 1, startedAt: '2026-11-23' };
  const running = app.render().find(n => n?.props?.accessibilityLabel === 'Protocol van Moos');
  assert.ok(text(running).includes("Moos' herstelplan is in volle gang!") && text(running).includes('Dag 3 · Week 1'));
});

test('partially completed intake has its own heading and continuation link', () => {
  const app = screen();
  app.purchases.partial[0].has_answers = 1;
  const tree = app.render();
  assert.ok(text(tree).includes("Je bent al begonnen met partial's intake!"));
  const card = tree.find(n => n?.props?.accessibilityLabel === 'Ga verder met je intake voor partial');
  assert.ok(card);
  card.props.onPress();
  assert.equal(app.routes.at(-1).params.bookingId, 'i3');
});

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
  assert.ok(tree.includes("purchased's intake"));
  assert.ok(tree.indexOf('Welkom, Tim') < tree.indexOf("purchased's intake"));
  assert.ok(tree.indexOf("purchased's intake") < tree.indexOf('Ontdek in de bibliotheek'));
  app.entry().props.onPress();
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'i1' } }]);
});
test('Home shows every horse\'s intake as its own card, whichever horse is selected and also offline', () => {
  const app = screen();
  const open = () => app.render().filter(n => n?.type === 'Pressable' && /^(Start de intake|Ga verder met de intake) voor /.test(n.props.accessibilityLabel || ''));
  for (const horse of ['unpurchased', 'partial']) {
    app.select(horse); app.ready = false;
    assert.equal(open().length, 2, 'Nova and the follow-up intake each have a card');
  }
  const tree = app.render();
  for (const title of ["purchased's intake", "submitted's EquiBoost traject", "partial's intake"]) assert.ok(tree.includes(title));
  assert.ok(tree.includes("partial's intake"), 'the trajectory identifies its horse');
  open()[1].props.onPress();
  assert.deepEqual(app.routes, [{ pathname: '/intake', params: { bookingId: 'i3' } }]);
  assert.ok(text(app.render()).includes("submitted's intake is verstuurd!"));
  assert.ok(text(app.render()).includes('Binnen 5 werkdagen staat het herstelplan hier voor je klaar.'));
  assert.ok(!app.render().some(n => n?.props?.accessibilityLabel === 'Intake bekijken voor submitted'));
});
test('finished protocols stay visible alongside a newly purchased intake', () => {
  const app = screen();
  app.purchases.purchased.push({ id: 'old', title: 'Vorig protocol', status: 'completed', booking_id: 'old-intake', submitted_at: null });
  assert.ok(app.render().includes('Vorig protocol')); assert.ok(app.render().includes('Afgerond'));
  assert.equal(app.render().filter(n => n?.type === 'Pressable' && /^(Start de intake|Ga verder met de intake) voor /.test(n.props.accessibilityLabel || '')).length, 2, 'only the two open intakes, not the finished protocol');
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
  const partial = app.render().filter(n => n?.type === 'Pressable' && /^(Start de intake|Ga verder met de intake) voor /.test(n.props.accessibilityLabel || ''))[1];
  partial.props.onPress();
  assert.equal(app.selected(), 'partial', 'the intake looks up its booking under the selected horse');
  assert.deepEqual(app.routes.at(-1), { pathname: '/intake', params: { bookingId: 'i3' } });
});

test('Home shows an active protocol card for every horse that has one, with one dashboard request', () => {
  const app = screen();
  app.activeProtocols.push('purchased', 'partial');
  app.select('purchased');
  const tree = app.render();
  const cards = tree.filter(n => n?.type === 'Pressable' && n.props.accessibilityLabel?.startsWith('Protocol van '));
  assert.deepEqual(cards.map(card => text(card).split("'s EquiBoost traject")[0]), ['partial', 'purchased'], 'one card per horse, in the server order');
  assert.ok(text(cards[0]).includes("partial's herstelplan is in volle gang!") && text(cards[0]).includes('Dag 10 · Week 2'), 'every card uses its server summary');
  cards[0].props.onPress();
  assert.equal(app.selected(), 'partial', 'Protocol opens on the horse of the card');
  assert.equal(app.routes.at(-1).pathname, '/(tabs)/(pager)/protocol');
});

test('an older cached dashboard without per-horse summaries still shows the selected horse\'s EquiBoost traject', () => {
  const app = screen();
  app.activeProtocols.push('partial', 'purchased');
  app.select('partial');
  app.oldCachedResponse = true;
  const cards = app.render().filter(n => n?.type === 'Pressable' && text(n).includes('Vink af wat je vandaag hebt gegeven'));
  assert.equal(cards.length, 1);
  assert.ok(text(cards[0]).includes("partial's herstelplan is in volle gang!"));
});

test('a published plan without a protocol card says it is ready, while active drafts still say it is being written', () => {
  const app = screen();
  // No dashboard card (e.g. offline or no response yet): the local query decides.
  app.purchases.submitted[0] = { ...app.purchases.submitted[0], title: 'Persoonlijk protocol', status: 'active', published: 1 };
  let copy = text(app.render());
  assert.ok(copy.includes('Herstelplan van submitted'));
  assert.ok(copy.includes('Je herstelplan staat klaar.'));
  assert.ok(!copy.includes('Je behandelaar stelt je herstelplan op.'));
  assert.ok(app.render().some(n => n?.type === 'Pressable' && n.props.accessibilityLabel?.startsWith('Intake bekijken voor ')));
  app.purchases.submitted[0] = { ...app.purchases.submitted[0], status: 'paused' };
  assert.ok(text(app.render()).includes("submitted's herstelplan is gepauzeerd"));
  // Unpublished (or not yet published) plans can be active drafts: keep the writing copy.
  for (const row of [{ status: 'active', published: 0 }, { status: 'draft', published: 1 }]) {
    app.purchases.submitted[0] = { ...app.purchases.submitted[0], ...row };
    copy = text(app.render());
    assert.ok(copy.includes("submitted's intake is verstuurd!"));
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
    CREATE TABLE intake_answers (response_id TEXT, section_id TEXT, field_id TEXT, value TEXT);
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
  db.prepare('INSERT INTO intake_answers VALUES (?, ?, ?, ?)').run('i-draft', 'paard', 'klachten', '"Ingevuld"');
  const progress = db.prepare(queries.PROTOCOLS_FOR_ALL_HORSES).all('u');
  assert.equal(progress.find(row => row.id === 'draft').has_answers, 1);
  assert.equal(progress.find(row => row.id === 'past').has_answers, 0);
});

test('the local query marks only the plan the Protocol tab will show, with the dashboard order and id tie-break', async () => {
  const { DatabaseSync } = await import('node:sqlite');
  const queries = await import('../lib/intake/protocol-queries.ts');
  const db = new DatabaseSync(':memory:');
  db.exec(`CREATE TABLE protocols (id TEXT, title TEXT, status TEXT, horse_id TEXT, published_at TEXT, created_at TEXT);
    CREATE TABLE intake_bookings (id TEXT, protocol_id TEXT, user_id TEXT, status TEXT, submitted_at TEXT, completed_at TEXT);
    CREATE TABLE intake_answers (response_id TEXT, section_id TEXT, field_id TEXT, value TEXT);
    CREATE TABLE horses (id TEXT, name TEXT, status TEXT);
    INSERT INTO horses VALUES ('h', 'Nova', 'active');`);
  const shown = () => Object.fromEntries(db.prepare(queries.PROTOCOLS_FOR_HORSE).all('h', 'u').map(row => [row.id, row.shown_in_tab]));
  const add = (id, status, published, withIntake = true) => {
    db.prepare("INSERT INTO protocols VALUES (?, 'Plan', ?, 'h', ?, '2026-01-01')").run(id, status, published);
    if (withIntake) db.prepare("INSERT INTO intake_bookings VALUES (?, ?, 'u', 'pending', '2026-01-01', NULL)").run(`i-${id}`, id);
  };
  add('b-paused', 'paused', '2026-02-01 10:00:00');
  add('c-paused', 'paused', '2026-02-01T10:00:00Z');
  add('a-old', 'paused', '2026-01-01 10:00:00');
  // Equal publication times: the lowest id wins, as on the server.
  assert.deepEqual(shown(), { 'b-paused': 1, 'c-paused': 0, 'a-old': 0 });
  // A future active plan is not shown yet; a later paused plan, even without its own intake card, wins.
  add('future', 'active', '2999-01-01 10:00:00');
  add('later', 'paused', '2026-03-01 10:00:00', false);
  assert.deepEqual(shown(), { 'b-paused': 0, 'c-paused': 0, 'a-old': 0, future: 0 });
  // A visible active plan always wins over every paused plan.
  db.exec("DELETE FROM protocols WHERE id = 'later'");
  add('running', 'active', '2026-01-15 10:00:00', false);
  assert.deepEqual(shown(), { 'b-paused': 0, 'c-paused': 0, 'a-old': 0, future: 0 });
});

test('a paused plan card links to the Protocol tab only when the tab shows that exact plan', () => {
  const app = screen();
  app.purchases.submitted[0] = { ...app.purchases.submitted[0], status: 'paused', published: 1, shown_in_tab: 0 };
  let card = app.render().find(n => n?.type === 'Pressable' && text(n).includes("submitted's herstelplan is gepauzeerd"));
  assert.ok(card && card.props.disabled && !card.props.accessibilityRole);
  assert.ok(!text(card).includes('Bekijk je herstelplan'));
  app.purchases.submitted[0].shown_in_tab = 1;
  card = app.render().find(n => n?.type === 'Pressable' && text(n).includes("submitted's herstelplan is gepauzeerd"));
  assert.ok(!card.props.disabled && text(card).includes('Bekijk je herstelplan →'));
  card.props.onPress();
  assert.deepEqual(app.routes.at(-1).params, { horseId: 'submitted', protocolId: 'p2', tab: 'vandaag' });
});

test('actionableOnly keeps open intakes and drops completed, excluded and unopenable rows', () => {
  const routes = [];
  const rows = [
    { id: 'done', title: 'Oud plan', status: 'completed', booking_id: 'b0', submitted_at: '2026-01-01', published: 1 },
    { id: 'next', title: 'Persoonlijk protocol', status: 'draft', booking_id: 'b1', submitted_at: null },
    { id: 'sent', title: 'Persoonlijk protocol', status: 'draft', booking_id: 'b2', submitted_at: '2026-10-01' },
    { id: 'behind', title: 'Plan', status: 'paused', booking_id: 'b3', submitted_at: '2026-01-01', published: 1, shown_in_tab: 0 },
    { id: 'shown', title: 'Plan', status: 'completed', booking_id: 'b4', submitted_at: '2026-01-01', published: 1 },
  ].map(row => ({ ...row, horse_id: 'h', horse_name: 'Nova' }));
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const { PurchasedProtocols } = load('../components/intake/PurchasedProtocols.tsx', {
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable' },
    'expo-router': { router: { push: route => routes.push(route) } },
    '@/db/hooks': { useCurrentHorseId: () => 'h', useCurrentUserId: () => 'u' },
    '@/db/provider': { useDb: () => ({ selectHorse() {} }) },
    '@powersync/react': { useQuery: () => ({ data: rows }) },
    '@/components/ui/styles': uiStyles, '@/lib/protocol-evaluation': evaluationHelpers,
    '@/lib/intake/submission': { formatIntakeDate: () => '1 oktober 2026' },
    '@/lib/intake/protocol-queries': { PROTOCOLS_FOR_HORSE: 'for-horse', PROTOCOLS_FOR_ALL_HORSES: 'all-horses' },
  });
  const cards = flatten(PurchasedProtocols({ excludeProtocolIds: ['shown'], actionableOnly: true })).filter(n => n?.type === 'Pressable');
  assert.deepEqual(cards.map(card => card.props.accessibilityLabel), ['Start de intake voor Nova']);
  cards[0].props.onPress();
  assert.deepEqual(routes.at(-1), { pathname: '/intake', params: { bookingId: 'b1' } });
  // Without the option every row keeps its card, as on Home.
  assert.equal(flatten(PurchasedProtocols({})).filter(n => n?.type === 'Pressable').length, 5);
});

test('Home removes only the intake card whose published protocol already has a protocol card', () => {
  const app = screen();
  app.activeProtocols.push('submitted');
  app.purchases.submitted[0].id = 'p-submitted';
  const tree = app.render();
  assert.ok(!tree.some(n => n?.type === 'Pressable' && n.props.accessibilityLabel?.startsWith('Intake bekijken voor ')));
  assert.equal(tree.filter(n => n?.type === 'Pressable' && /^(Start de intake|Ga verder met de intake) voor /.test(n.props.accessibilityLabel || '')).length, 2);
  assert.ok(text(tree).includes("submitted's EquiBoost traject"));
  // A second purchase for the same horse is a different intake and remains available.
  app.purchases.submitted.push({ id: 'new-purchase', title: 'Nieuw protocol', status: 'draft', booking_id: 'new-intake' });
  assert.equal(app.render().filter(n => n?.type === 'Pressable' && /^(Start de intake|Ga verder met de intake) voor /.test(n.props.accessibilityLabel || '')).length, 3);
});

test('an unstarted Home protocol opens that horse\'s order list and shows no day, week, phase or progress', () => {
  const app = screen();
  app.activeProtocols.push('submitted');
  app.preparingProtocols.push('submitted');
  app.purchases.submitted[0].id = 'p-submitted';
  const cards = () => app.render().filter(n => n?.type === 'Pressable' && n.props.accessibilityLabel?.startsWith('Protocol van '));
  const card = cards()[0];
  assert.ok(text(card).includes('Het herstelplan voor submitted staat klaar!'));
  assert.ok(text(card).includes("Controleer welke kruiden, supplementen en andere producten je nodig hebt. Bestel wat je nog niet in huis hebt, zodat je op tijd met submitted's herstelplan kunt starten."));
  assert.ok(!text(card).includes('Dag ') && !text(card).includes('Week ') && !text(card).includes('Actief voor'));
  assert.ok(!flatten(card).some(n => n?.props?.style?.width));
  card.props.onPress();
  assert.equal(app.selected(), 'submitted');
  assert.equal(app.routes.at(-1).params.horseId, 'submitted');
  assert.equal(app.routes.at(-1).params.protocolId, 'p-submitted');
  assert.equal(app.routes.at(-1).params.orderList, '1');
  app.preparingProtocols.length = 0;
  assert.ok(text(cards()[0]).includes("submitted's herstelplan is in volle gang!"));
  assert.ok(text(cards()[0]).includes('Dag 10 · Week 2'));
});

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
import * as filters from '../lib/library-filter.ts';
import * as library from '../lib/library.ts';

const code = ts.transpileModule(await readFile(new URL('../app/(tabs)/(pager)/library.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;
function screen() {
  const values = []; let cursor = 0, focused = 0;
  const catalog = [
    { id: 'free', title: 'Gratis hooi', format: 'article', creditCost: 0 },
    { id: 'paid', title: 'Hooi meten', format: 'video', creditCost: 2 },
    { id: 'plus', title: 'Plus les', format: 'audio', creditCost: 0, isPlus: true },
  ];
  const bookmarks = { itemIds: ['paid'], pendingIds: [], error: null };
  let refreshes = 0;
  const resource = { data: { hasPlus: false, unlockedIds: [], credits: 7 }, error: null, refresh: () => refreshes++ };
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const modules = {
    '@/components/ui/KeyboardForm': { KeyboardViewport: 'KeyboardAvoidingView', KeyboardScrollView: 'ScrollView', KeyboardTextInput: 'TextInput' },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: {
      useState(initial) { const index = cursor++; if (!(index in values)) values[index] = initial; return [values[index], value => { values[index] = typeof value === 'function' ? value(values[index]) : value; }]; },
      useEffect() {}, useMemo: fn => fn(), useRef: () => ({ current: { focus: () => focused++, measureInWindow: fn => fn(20, 160, 120, 44) } }),
    },
    'react-native': { ...Object.fromEntries(['View', 'Text', 'ScrollView', 'Pressable', 'TextInput', 'ActivityIndicator', 'Modal'].map(name => [name, name])), useWindowDimensions: () => ({ width: 400, height: 800 }) },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { useLocalSearchParams: () => ({}) },
    'lucide-react-native': { Search: 'Search', ChevronDown: 'ChevronDown', Check: 'Check', X: 'X' },
    '@/components/library/LibraryCard': { LibraryCard: 'LibraryCard' },
    '@/components/ui/Chip': { Chip: 'Chip' },
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 76 },
    '@/hooks/useLibraryResource': { useLibraryResource: () => resource },
    '@/hooks/useLibraryBookmarks': { useLibraryBookmarks: () => bookmarks },
    '@/lib/library': library, '@/lib/library-filter': filters,
    '@/db/hooks': {
      useLibraryCategories: () => [{ id: 'hay', label: 'Hooi' }],
      useLibraryItems: () => catalog,
      useLibraryItemCategories: () => [{ itemId: 'paid', categoryId: 'hay' }, { itemId: 'free', categoryId: 'hay' }],
      useValue: () => 'Zoeken',
    },
  };
  const exports = {}; new Function('require', 'exports', code)(name => {
    assert.ok(modules[name], `unmocked module ${name}`); return modules[name];
  }, exports);
  const flatten = node => !node ? [] : Array.isArray(node) ? node.flatMap(flatten) : typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
  const render = () => { cursor = 0; return flatten(exports.default()); };
  const press = label => { const node = render().find(node => node.type === 'Pressable' && node.props.accessibilityLabel === label); assert.ok(node, label); node.props.onPress(); };
  const ids = () => render().filter(node => node.type === 'LibraryCard').map(node => node.props.item.id);
  const select = label => {
    render().find(node => node.type === 'Pressable' && node.props.accessibilityLabel?.startsWith('Bibliotheekfilter:')).props.onPress();
    press(label);
    assert.ok(!render().some(node => node.type === 'Modal'));
  };
  return { render, press, select, ids, bookmarks, resource, refreshes: () => refreshes, focused: () => focused };
}

test('actual Library screen composes saved, category and search; X preserves filters and focus', () => {
  const app = screen();
  assert.deepEqual(app.ids(), ['free', 'paid', 'plus']);
  app.select('Opgeslagen'); app.press('Hooi');
  assert.deepEqual(app.ids(), ['paid']);
  app.render().find(node => node.type === 'TextInput').props.onChangeText('missing');
  assert.deepEqual(app.ids(), []);
  app.press('Zoekterm wissen');
  assert.deepEqual(app.ids(), ['paid']); assert.equal(app.focused(), 1);
  assert.ok(!app.render().some(node => node.props.accessibilityLabel === 'Zoekterm wissen'));
  assert.equal(app.render().find(node => node.type === 'LibraryCard').props.showBookmark, true);
  app.bookmarks.itemIds = [];
  assert.deepEqual(app.ids(), []);
  assert.ok(app.render().some(node => node.props.children === 'Nog niets opgeslagen'));
});

test('the dropdown has exactly four choices and Alles preserves category and search filters', () => {
  const app = screen();
  assert.ok(!app.render().some(n => n.props.accessibilityRole === 'radio'));
  app.press('Bibliotheekfilter: Alles');
  const choices = app.render().filter(n => n.props.accessibilityRole === 'radio');
  assert.deepEqual(choices.map(n => n.props.accessibilityLabel), ['Alles', 'Mijn items', 'Opgeslagen', 'Gratis']);
  assert.deepEqual(choices.map(n => n.props.accessibilityState.checked), [true, false, false, false]);
  app.press('Bibliotheekfilter sluiten');
  assert.ok(!app.render().some(n => n.type === 'Modal'));
  app.select('Opgeslagen'); app.press('Hooi');
  app.render().find(node => node.type === 'TextInput').props.onChangeText('meten');
  app.select('Alles');
  assert.deepEqual(app.ids(), ['paid']);
  app.press('Zoekterm wissen');
  assert.deepEqual(app.ids(), ['free', 'paid']);
  app.press('Hooi');
  assert.deepEqual(app.ids(), ['free', 'paid', 'plus']);
  app.press('Bibliotheekfilter: Alles');
  app.render().find(n => n.type === 'Modal').props.onRequestClose();
  assert.ok(!app.render().some(n => n.type === 'Modal'));
});

test('Mijn items excludes free items while retaining purchases and eligible Plus content', () => {
  const app = screen();
  app.select('Mijn items');
  assert.deepEqual(app.ids(), [], 'credits alone do not unlock paid items');
  app.resource.data.unlockedIds = ['free', 'paid'];
  assert.deepEqual(app.ids(), ['paid'], 'even previously unlocked free items belong under Gratis');
  app.resource.data.hasPlus = true;
  assert.deepEqual(app.ids(), ['paid', 'plus']);
  app.resource.data.hasPlus = false;
  app.resource.data.unlockedIds = ['paid', 'plus'];
  assert.deepEqual(app.ids(), ['paid', 'plus'], 'a prior purchase keeps Plus-only content available');
  app.press('Hooi');
  assert.deepEqual(app.ids(), ['paid']);
  app.render().find(node => node.type === 'TextInput').props.onChangeText('missing');
  assert.deepEqual(app.ids(), []);
  app.press('Zoekterm wissen');
  assert.deepEqual(app.ids(), ['paid']);
});

test('Gratis shows only free non-Plus items and composes with search and categories', () => {
  const app = screen();
  app.resource.data.hasPlus = true;
  app.resource.data.unlockedIds = ['paid', 'plus'];
  app.select('Gratis');
  assert.deepEqual(app.ids(), ['free'], 'purchased and Plus content is not free');
  app.press('Hooi');
  assert.deepEqual(app.ids(), ['free']);
  app.render().find(node => node.type === 'TextInput').props.onChangeText('meten');
  assert.deepEqual(app.ids(), []);
  app.press('Zoekterm wissen');
  assert.deepEqual(app.ids(), ['free']);
  app.resource.data = null;
  app.resource.error = 'Verbinding niet beschikbaar.';
  assert.deepEqual(app.ids(), ['free'], 'free filtering does not depend on loading access');
  app.select('Alles');
  assert.deepEqual(app.ids(), ['free', 'paid']);
  app.bookmarks.itemIds = ['free'];
  app.select('Opgeslagen');
  assert.deepEqual(app.ids(), ['free'], 'saved free items remain in Opgeslagen');
});

test('Mijn items waits for access and offers retry after a failed load', () => {
  const app = screen();
  app.resource.data = null;
  app.select('Mijn items');
  assert.deepEqual(app.ids(), []);
  assert.ok(app.render().some(n => n.type === 'ActivityIndicator'));
  assert.ok(!app.render().some(n => n.props.children === 'Geen bibliotheekitems gevonden.'));
  app.resource.error = 'Verbinding niet beschikbaar.';
  assert.ok(!app.render().some(n => n.type === 'ActivityIndicator'));
  const retry = app.render().find(n => n.type === 'Pressable' && Array.isArray(n.props.children?.props?.children) && n.props.children.props.children.includes(app.resource.error));
  assert.ok(retry);
  app.bookmarks.refresh = () => {};
  retry.props.onPress();
  assert.equal(app.refreshes(), 1);
  app.select('Alles');
  assert.deepEqual(app.ids(), ['free', 'paid', 'plus']);
});

test('library retains the current credit balance', () => {
  const app = screen();
  assert.ok(app.render().some(n => n.type === 'Text' && Array.isArray(n.props.children) && n.props.children.join('') === 'Je credits: 7'));
});

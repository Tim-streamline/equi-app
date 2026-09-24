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
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: {
      useState(initial) { const index = cursor++; if (!(index in values)) values[index] = initial; return [values[index], value => { values[index] = typeof value === 'function' ? value(values[index]) : value; }]; },
      useEffect() {}, useMemo: fn => fn(), useRef: () => ({ current: { focus: () => focused++ } }),
    },
    'react-native': Object.fromEntries(['View', 'Text', 'ScrollView', 'Pressable', 'TextInput', 'ActivityIndicator'].map(name => [name, name])),
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { useLocalSearchParams: () => ({}) },
    'lucide-react-native': { Search: 'Search', SlidersHorizontal: 'SlidersHorizontal', X: 'X' },
    '@/components/library/LibraryCard': { LibraryCard: 'LibraryCard' },
    '@/components/ui/Chip': { Chip: 'Chip' },
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 76 },
    '@/hooks/useLibraryResource': { useLibraryResource: () => ({ data: { hasPlus: false, unlockedIds: [] } }) },
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
  return { render, press, ids, bookmarks, focused: () => focused };
}

test('actual Library screen composes saved, price, category and search; X preserves filters and focus', () => {
  const app = screen();
  assert.deepEqual(app.ids(), ['free', 'paid', 'plus']);
  app.press('Opgeslagen'); app.press('Hooi'); app.press('Filters'); app.press('2 credits');
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

test('Alles includes locked items; accessible-only excludes locked items; clearing restores discovery', () => {
  const app = screen(); app.press('Filters'); app.press('Al ontgrendeld');
  assert.deepEqual(app.ids(), ['free']);
  app.press('Al ontgrendeld verwijderen');
  assert.deepEqual(app.ids(), ['free', 'paid', 'plus']);
  app.press('Opgeslagen'); app.press('Alles');
  assert.deepEqual(app.ids(), ['free', 'paid', 'plus']);
});

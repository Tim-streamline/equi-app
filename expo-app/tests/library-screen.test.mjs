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
  const groups = [{id:'topic',label:'Onderwerp'},{id:'level',label:'Niveau'}];
  const categories = [{id:'hay',label:'Hooi',groupId:'topic'},{id:'lesson',label:'Lessen',groupId:'topic'},{id:'basic',label:'Basis',groupId:'level'}];
  const history = [{itemId:'paid',lastViewedAt:new Date().toISOString()}];
  const values = []; let cursor = 0, focused = 0;
  const catalog = [
    { id: 'free', title: 'Gratis hooi', format: 'article', creditCost: 0 },
    { id: 'paid', title: 'Hooi meten', format: 'video', creditCost: 2 },
    { id: 'plus', title: 'Plus les', format: 'audio', creditCost: 0, isPlus: true },
  ];
  const localAccess = { grants: [], isLoading: true };
  const bookmarks = { itemIds: ['paid'], pendingIds: [], error: null };
  let refreshes = 0, header = null;
  const resource = { data: { hasPlus: false, unlockedIds: [], credits: 7 }, error: null, refresh: () => refreshes++ };
  const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
  const modules = {
    '@/components/ui/KeyboardForm': { KeyboardViewport: 'KeyboardAvoidingView', KeyboardScrollView: 'ScrollView', KeyboardTextInput: 'TextInput' },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: {
      useState(initial) { const index = cursor++; if (!(index in values)) values[index] = initial; return [values[index], value => { values[index] = typeof value === 'function' ? value(values[index]) : value; }]; },
      useEffect() {}, useMemo: fn => fn(), useRef: () => ({ current: { focus: () => focused++, blur: () => {}, measureInWindow: fn => fn(20, 160, 120, 44) } }),
    },
    'react-native': {
      ...Object.fromEntries(['View', 'Text', 'ScrollView', 'Pressable', 'TextInput', 'ActivityIndicator', 'Modal'].map(name => [name, name])),
      // Render every row so assertions see the whole list.
      FlatList: ({ data, renderItem, ListHeaderComponent, ListEmptyComponent }) => ({ type: 'FlatList', props: { children: [ListHeaderComponent, ...(data.length ? data.map((item, index) => renderItem({ item, index })) : [ListEmptyComponent])] } }),
      useWindowDimensions: () => ({ width: 400, height: 800 }),
    },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { useLocalSearchParams: () => ({}), router: { push() {} } },
    '@/components/ui/OfflineStrip': { OfflineStrip: 'OfflineStrip' },
    '@/components/navigation/headerActions': { useHeaderAction: action => { header = action?.(); } },
    'lucide-react-native': { Search: 'Search', ChevronDown: 'ChevronDown', Check: 'Check', X: 'X', SlidersHorizontal: 'SlidersHorizontal', ChevronLeft: 'ChevronLeft' },
    '@/components/library/LibraryCard': { LibraryCard: 'LibraryCard' },
    '@/components/ui/Chip': { Chip: 'Chip' },
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 76 },
    '@/hooks/useLibraryAccessRecords': { useLibraryAccessRecords: () => localAccess },
    '@/hooks/useLibraryResource': { useLibraryResource: () => resource },
    '@/hooks/useLibraryBookmarks': { useLibraryBookmarks: () => bookmarks },
    '@/lib/library': library, '@/lib/library-filter': filters,
    '@/db/hooks': {
      useLibraryCategories: () => categories,
      useLibraryFilterGroups: () => groups,
      useLibraryViewingHistory: () => history,
      useLibraryItems: () => catalog,
      useLibraryItemCategories: () => [{ itemId: 'paid', categoryId: 'hay' }, { itemId: 'free', categoryId: 'hay' }, { itemId: 'plus', categoryId: 'lesson' }, {itemId:'paid',categoryId:'basic'}],
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
  const category = label => { press('Filters openen'); press(label); press('Filters sluiten'); };
  const headerAction = () => { render(); return flatten(header); };
  return { render, headerAction, press, category, ids, bookmarks, resource, localAccess, groups, categories, history, refreshes: () => refreshes, focused: () => focused };
}

test('filters are grouped, combine within/across groups, count live, and persist as removable chips', () => {
 const app=screen(); app.press('Filters openen');
 for(const title of ['Persoonlijk','Onderwerp','Niveau','Soort']) assert.ok(app.render().some(n=>n.props.children===title));
 app.press('Hooi'); app.press('Lessen'); assert.deepEqual(app.ids(),['free','paid','plus']);
 app.press('Basis'); assert.deepEqual(app.ids(),['paid']);
 app.press('Toon 1 item');
 assert.equal(app.render().find(n=>n.props.accessibilityLabel==='Filters openen').props.accessibilityHint,'3 actieve filters');
 app.press('Verwijder filter: Basis'); assert.deepEqual(app.ids(),['free','paid','plus']);
 app.press('Verwijder filter: Hooi'); assert.deepEqual(app.ids(),['plus']);
});
test('personal filters are multi-select and unlocked means purchased, not merely free or subscribed', () => {
 const app=screen(); app.resource.data.unlockedIds=['paid'];app.resource.data.hasPlus=true;
 app.category('Ontgrendeld'); assert.deepEqual(app.ids(),['paid']);
 app.category('Nog niet bekeken');assert.deepEqual(app.ids(),['free','paid','plus']);
 app.press('Verwijder filter: Ontgrendeld');assert.deepEqual(app.ids(),['free','plus']);
 app.press('Verwijder filter: Nog niet bekeken');app.category('Recent bekeken');assert.deepEqual(app.ids(),['paid']);
 app.history[0].lastViewedAt='2020-01-01';assert.deepEqual(app.ids(),[]);
});
test('all clear actions remove personal, category and type filters while preserving search', () => {
 const app=screen();app.render().find(n=>n.type==='TextInput').props.onChangeText('meten');
 app.category('Opgeslagen');app.category('Hooi');app.category('Video');assert.deepEqual(app.ids(),['paid']);
 app.press('Filters openen');app.press('Alle filters wissen');app.press('Toon 1 item');
 assert.ok(!app.render().some(n=>n.props.accessibilityLabel?.startsWith('Verwijder filter:')));
 assert.equal(app.render().find(n=>n.type==='TextInput').props.value,'meten');
 app.press('Zoekterm wissen');assert.equal(app.focused(),1);assert.deepEqual(app.ids(),['free','paid','plus']);
});
test('new admin groups and categories appear without frontend changes; archived choices stop filtering', () => {
 const app=screen();app.groups.push({id:'new',label:'Levensfase'});app.categories.push({id:'senior',label:'Senior',groupId:'new'});
 app.press('Filters openen');app.press('Senior');assert.deepEqual(app.ids(),[]);
 app.categories.pop();assert.deepEqual(app.ids(),['free','paid','plus']);
 assert.ok(app.render().some(n=>n.props.children==='Levensfase'));
 assert.ok(!app.render().some(n=>n.props.accessibilityLabel==='Verwijder filter: Senior'));
});
test('unlocked filters use account-scoped synced grants offline, with loading and retry states', () => {
 const app=screen();app.resource.data=null;app.category('Ontgrendeld');assert.deepEqual(app.ids(),[]);
 app.press('Filters openen');app.press('Terug naar bibliotheek');
 app.localAccess.isLoading=false;app.localAccess.grants=[{item_id:'paid',reason:'unlocked'},{item_id:'plus',reason:'plus'}];
 assert.deepEqual(app.ids(),['paid']);app.localAccess.grants=[];assert.deepEqual(app.ids(),[]);
});
test('credit balance remains visible and missing saved data does not show a misleading zero count', () => {
 const app=screen();const credits=app.headerAction();assert.ok(credits.some(n=>n.props.accessibilityLabel==='Je credits: 7. Credits bekijken'));assert.ok(credits.some(n=>Array.isArray(n.props.children)&&n.props.children.join('')==='7 credits'));
 app.bookmarks.itemIds=null;app.category('Opgeslagen');app.press('Filters openen');app.press('Terug naar bibliotheek');
});

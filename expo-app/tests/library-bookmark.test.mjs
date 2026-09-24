import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
import { libraryAccessLabel, libraryFormat } from '../lib/library.ts';
import { createLibraryBookmarkStore } from '../lib/library-bookmarks.ts';

const code = ts.transpileModule(await readFile(new URL('../components/library/LibraryBookmarkButton.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
async function button(initial, failure = false) {
  const requests = [], alerts = [];
  const store = createLibraryBookmarkStore(async (path, method) => {
    if (path === '/bookmarks') return { itemIds: initial ? ['lesson'] : [] };
    requests.push({ path, method });
    if (failure) throw Error('Offline');
    return { bookmarked: method === 'PUT' };
  });
  await store.refresh();
  const modules = {
    'react/jsx-runtime': { jsx: (type, props) => ({ type, props }) },
    'react-native': { Pressable: 'Pressable', Alert: { alert: (...args) => alerts.push(args) } },
    'lucide-react-native': { Bookmark: 'Bookmark' },
    '@/hooks/useLibraryBookmarks': { useLibraryBookmarks: () => ({ ...store.getSnapshot(), refresh: store.refresh, toggle: store.toggle }) },
  };
  const exports = {}; new Function('require', 'exports', code)(name => modules[name], exports);
  return { render: () => exports.LibraryBookmarkButton({ itemId: 'lesson' }), requests, alerts, store };
}
const press = fixture => fixture.render().props.onPress({ stopPropagation() {} });
const settle = () => new Promise(resolve => setImmediate(resolve));
test('actual bookmark button immediately updates shared list state, then persists save and remove', async () => {
  const fixture = await button(false);
  press(fixture);
  assert.deepEqual(fixture.store.getSnapshot().itemIds, ['lesson']);
  await settle();
  assert.deepEqual(fixture.requests, [{ path: '/lesson/bookmark', method: 'PUT' }]);
  assert.equal(fixture.render().props.accessibilityState.selected, true);
  press(fixture);
  assert.deepEqual(fixture.store.getSnapshot().itemIds, []);
  await settle();
  assert.equal(fixture.requests[1].method, 'DELETE');
  assert.equal(fixture.render().props.accessibilityState.selected, false);
});
test('failed optimistic remove restores the list entry and reports the error', async () => {
  const fixture = await button(true, true);
  press(fixture);
  assert.deepEqual(fixture.store.getSnapshot().itemIds, []);
  await settle();
  assert.equal(fixture.render().props.accessibilityState.selected, true);
  assert.deepEqual(fixture.alerts, [['Bewaren mislukt', 'Offline']]);
});
test('a stale list response cannot resurrect a removed bookmark and duplicate mutations are suppressed', async () => {
  let resolveRead, resolveWrite, reads = 0, writes = 0;
  const store = createLibraryBookmarkStore(async (_path, method) => {
    if (method) { writes++; return new Promise(resolve => { resolveWrite = resolve; }); }
    if (++reads === 1) return { itemIds: ['lesson'] };
    return new Promise(resolve => { resolveRead = resolve; });
  });
  await store.refresh();
  const read = store.refresh();
  const mutation = store.toggle('lesson');
  await store.toggle('lesson');
  assert.equal(writes, 1);
  resolveRead({ itemIds: ['lesson'] }); await read;
  assert.deepEqual(store.getSnapshot().itemIds, []);
  resolveWrite({ bookmarked: false }); await mutation;
  assert.deepEqual(store.getSnapshot().itemIds, []);
});
test('concurrent mutations keep independent outcomes and stores are isolated per account', async () => {
  let failA, saveB;
  const store = createLibraryBookmarkStore(async (path, method) => !method ? { itemIds: [] } : new Promise((resolve, reject) => {
    if (path.startsWith('/a/')) failA = reject; else saveB = resolve;
  }));
  const other = createLibraryBookmarkStore(async () => ({ itemIds: [] }));
  await store.refresh(); await other.refresh();
  const a = store.toggle('a'); const b = store.toggle('b');
  saveB({ bookmarked: true }); await b;
  failA(Error('Offline')); await assert.rejects(a);
  assert.deepEqual(store.getSnapshot().itemIds, ['b']);
  assert.deepEqual(other.getSnapshot().itemIds, []);
});
test('card labels distinguish free, credits, Plus and existing unlocks', () => {
  const access = { hasPlus: false, unlockedIds: ['unlocked'] };
  assert.deepEqual(libraryAccessLabel({ id: 'free' }, access), { label: 'Gratis', locked: false });
  assert.deepEqual(libraryAccessLabel({ id: 'paid', creditCost: 2 }, access), { label: '2 credits', locked: true });
  assert.deepEqual(libraryAccessLabel({ id: 'plus', isPlus: true }, access), { label: 'Plus', locked: true });
  assert.equal(libraryAccessLabel({ id: 'unlocked', creditCost: 2 }, access).locked, false);
  assert.equal(libraryAccessLabel({ id: 'paid', creditCost: 2 }, { hasPlus: true, unlockedIds: [] }).locked, false);
  assert.equal(libraryFormat('audio'), 'Audio'); assert.equal(libraryFormat('video'), 'Video'); assert.equal(libraryFormat('article'), 'Artikel');
});

test('actual bookmark hook replaces cache across accounts and a new session for the same account', async () => {
  const hookCode = ts.transpileModule(await readFile(new URL('../hooks/useLibraryBookmarks.ts', import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
  }).outputText;
  let user = 'a'; const session = { revision: 1 }; let lateRead;
  const modules = {
    react: { useCallback: fn => fn, useMemo: fn => fn(), useSyncExternalStore: (_subscribe, getSnapshot) => getSnapshot() },
    'expo-router': { useFocusEffect() {} },
    '@/db/provider': { useDb: () => ({ currentUserId: user }) },
    '@/lib/library-bookmarks': { createLibraryBookmarkStore },
    '@/lib/account-session': { accountSession: session },
    './useLibraryResource': { libraryRequest: async () => user === 'a' ? new Promise(resolve => { lateRead = resolve; }) : { itemIds: [] } },
  };
  const exports = {}; new Function('require', 'exports', hookCode)(name => modules[name], exports);
  const old = exports.useLibraryBookmarks(); const pending = old.refresh();
  user = 'b'; session.revision++;
  await exports.useLibraryBookmarks().refresh();
  lateRead({ itemIds: ['private-a'] }); await pending;
  assert.deepEqual(exports.useLibraryBookmarks().itemIds, []);
  user = 'a'; session.revision++;
  assert.equal(exports.useLibraryBookmarks().itemIds, null);
  await assert.rejects(old.toggle('private-a'), /sessie is gewijzigd/);
});

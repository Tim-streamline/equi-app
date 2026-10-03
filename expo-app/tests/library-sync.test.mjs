import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { DatabaseSync } from 'node:sqlite';
import ts from 'typescript';
import { activeLibraryGrants, createLibrarySubscriptions } from '../lib/library-sync.ts';
const settle = () => new Promise(resolve => setImmediate(resolve));

test('subscriptions follow grants, revoke immediately and retry failed registrations', async () => {
  const calls = [], removed = [], errors = []; let fail = true;
  const manager = createLibrarySubscriptions({ syncStream(name, params) { return { async subscribe(options) {
    calls.push({ name, params, options });
    if (params.item_id === 'retry' && fail) { fail = false; throw new Error('temporary'); }
    return { unsubscribe: () => removed.push(params.item_id) };
  } }; } }, error => errors.push(error));
  manager.reconcile(['a', 'b', 'a', 'retry']); await settle();
  assert.equal(calls.length, 3); assert.equal(errors.length, 1);
  manager.reconcile(['a', 'b', 'retry']); await settle(); assert.equal(calls.length, 4);
  assert.ok(calls.every(c => c.name === 'library_content' && c.options.ttl === 0));
  manager.reconcile(['b']); assert.deepEqual(removed.sort(), ['a', 'retry']);
  manager.dispose(); assert.deepEqual(removed.sort(), ['a', 'b', 'retry']);
  manager.reconcile(['c']); assert.equal(calls.length, 4);
});

test('late subscription handles cannot survive revocation, regrant or logout', async () => {
  const pending = [], removed = [];
  const manager = createLibrarySubscriptions({ syncStream() { return { subscribe() { return new Promise(resolve => pending.push(resolve)); } }; } }, error => { throw error; });
  manager.reconcile(['a']); manager.reconcile([]); manager.reconcile(['a']);
  pending[0]({ unsubscribe: () => removed.push('old') }); await settle(); assert.deepEqual(removed, ['old']);
  manager.dispose(); pending[1]({ unsubscribe: () => removed.push('new') }); await settle(); assert.deepEqual(removed, ['old', 'new']);
});

test('grants expire offline at their UTC deadline with all supported timestamp forms', () => {
  const now = Date.parse('2026-10-01T12:00:00Z');
  const grant = expires_at => ({ user_id: 'alice', item_id: 'a', reason: 'plus', expires_at });
  assert.equal(activeLibraryGrants([grant(null), grant('2026-10-01 12:00:01'), grant('2026-10-01T12:00:01Z'), grant('2026-10-01T14:00:01+02:00')], now).length, 4);
  assert.deepEqual(activeLibraryGrants([grant('2026-10-01 12:00:00'), grant('2026-10-01T11:59:59Z'), grant('invalid')], now), []);
});

const compile = async path => ts.transpileModule(await readFile(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const hookCode = await compile('../hooks/useLibraryContent.ts');
const grantsCode = await compile('../hooks/useLibraryAccessRecords.ts');
const contentCode = await compile('../components/library/LibraryContent.tsx');
function load(code, modules) {
  const exports = {}; new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports); return exports;
}
function reader() {
  const db = new DatabaseSync(':memory:');
  db.exec(`CREATE TABLE library_items (id TEXT, title TEXT, description TEXT, format TEXT, hero_image_url TEXT, duration_label TEXT, credit_cost INTEGER, is_plus INTEGER, author_therapist_id TEXT, published_at TEXT);
    CREATE TABLE therapists (id TEXT, name TEXT);
    CREATE TABLE library_contents (id TEXT, body TEXT, chapters TEXT, attachments TEXT);
    CREATE TABLE library_item_access (id TEXT, user_id TEXT, item_id TEXT, reason TEXT, expires_at TEXT);
    INSERT INTO library_items VALUES ('lesson', 'Lesson', 'Preview', 'article', NULL, NULL, 2, 0, 'author', '2020-01-01');
    INSERT INTO therapists VALUES ('author', 'Teacher');
    INSERT INTO library_contents VALUES ('lesson', 'Offline article body', '[{"id":"chapter","title":"Chapter","startLabel":"00:00"}]', '[{"id":"file","title":"Worksheet","name":"file.pdf"}]');
    INSERT INTO library_item_access VALUES ('grant', 'alice', 'lesson', 'unlocked', NULL);`);
  const session = { currentUserId: 'alice', isLoggedIn: true, isConnected: false };
  const account = { data: null, error: 'Offline', refresh() {}, update(value) { this.data = value; } };
  const requests = [];
  const react = { useState: initial => [typeof initial === 'function' ? initial() : initial, () => {}], useEffect() {}, useRef: value => ({ current: value }) };
  const common = {
    react, 'react-native': { AppState: { addEventListener() { return { remove() {} }; } } },
    '@powersync/react': { useQuery: (sql, params) => ({ data: db.prepare(sql).all(...params), isLoading: false }) },
    '@/db/provider': { useDb: () => session }, '@/lib/library-sync': { activeLibraryGrants },
  };
  const { useLibraryAccessRecords } = load(grantsCode, common);
  const { useLibraryContent } = load(hookCode, { ...common,
    './useLibraryAccessRecords': { useLibraryAccessRecords },
    './useLibraryResource': { useLibraryResource: (path, enabled) => { if (enabled) requests.push(path); return enabled ? account : { ...account, data: null, error: null }; } },
  });
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react, 'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { Text: 'Text', View: 'View', ActivityIndicator: 'ActivityIndicator' },
    'expo-router': { router: { push() {} } }, '@/hooks/useLibraryContent': { useLibraryContent },
    '@/hooks/useLibraryResource': { libraryRequest: () => { throw new Error('Reader must not fetch content'); } },
    '@/lib/library': { libraryMetadata: () => 'Artikel' },
    '@/components/credits/TemporaryCreditButton': { TemporaryCreditButton: 'TemporaryCreditButton' },
    './MarkdownBody': { MarkdownBody: 'MarkdownBody' }, './LibraryAttachments': { LibraryAttachments: 'LibraryAttachments' }, './LibraryThumbnail': { LibraryThumbnail: 'LibraryThumbnail' },
  };
  for (const name of ['Button', 'Eyebrow', 'SectionTitle']) modules[`@/components/ui/${name}`] = { [name]: name };
  const { LibraryContent } = load(contentCode, modules);
  const flat = node => Array.isArray(node) ? node.flatMap(flat) : node && typeof node === 'object' ? [node, ...flat(node.props?.children)] : [node];
  return { db, session, account, requests, content: () => useLibraryContent('lesson'), render: () => flat(LibraryContent({ itemId: 'lesson' })) };
}

test('actual reader renders synced body, chapters and attachments offline without HTTP', () => {
  const app = reader();
  try {
    const nodes = app.render();
    assert.equal(nodes.find(n => n?.type === 'MarkdownBody').props.markdown, 'Offline article body');
    assert.ok(nodes.includes('Chapter'));
    assert.equal(nodes.find(n => n?.type === 'LibraryAttachments').props.attachments[0].name, 'file.pdf');
    assert.deepEqual(app.requests, []);
    app.db.prepare('UPDATE library_contents SET body = ? WHERE id = ?').run('Synced edit', 'lesson');
    assert.equal(app.content().data.body, 'Synced edit');
  } finally { app.db.close(); }
});

test('reader hides residual content after revoke, logout, account switch or expiry despite stale HTTP access', () => {
  for (const change of ['revoke', 'logout', 'switch', 'expire']) {
    const app = reader();
    try {
      app.account.data = { hasPlus: true, unlockedIds: ['lesson'], credits: 5 };
      if (change === 'revoke') app.db.exec('DELETE FROM library_item_access');
      if (change === 'logout') app.session.isLoggedIn = false;
      if (change === 'switch') app.session.currentUserId = 'bob';
      if (change === 'expire') app.db.exec("UPDATE library_item_access SET expires_at = '2020-01-01'");
      const data = app.content().data;
      assert.ok(!data?.canRead, change); assert.ok(!data?.body, change);
      assert.ok(!app.render().some(n => n?.type === 'MarkdownBody'), change);
    } finally { app.db.close(); }
  }
});

test('granted item awaiting initial sync shows download state instead of a second purchase', () => {
  const app = reader();
  try {
    app.db.exec('DELETE FROM library_contents');
    assert.equal(app.content().pendingContent, true);
    assert.ok(app.render().includes('Deze inhoud is nog niet gedownload. Maak verbinding om het item offline te kunnen lezen.'));
    assert.ok(!app.render().includes('Ontgrendel dit item')); assert.deepEqual(app.requests, []);
  } finally { app.db.close(); }
});

test('an open reader clears content and attachments when unpublication arrives after reconnecting', () => {
  for (const update of ["UPDATE library_items SET published_at = NULL", 'DELETE FROM library_items']) {
    const app = reader();
    try {
      assert.ok(app.render().some(n => n?.type === 'MarkdownBody'));
      app.session.isConnected = true;
      app.account.data = { hasPlus: true, unlockedIds: ['lesson'], credits: 5 };
      app.db.exec(update);
      const nodes = app.render();
      assert.equal(app.content().unavailable, true);
      assert.ok(nodes.includes('Dit bibliotheekitem is niet beschikbaar.'));
      assert.ok(!nodes.some(n => ['MarkdownBody', 'LibraryAttachments', 'ActivityIndicator'].includes(n?.type)));
      assert.ok(!nodes.includes('Chapter'));
    } finally { app.db.close(); }
  }
});

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
import * as library from '../lib/library.ts';
const compile = async path => ts.transpileModule(await readFile(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const contentCode = await compile('../components/library/LibraryContent.tsx');
const requestCode = await compile('../hooks/useLibraryResource.ts');
const connectorCode = await compile('../db/connector.ts');
function load(code, modules) {
  const exports = {}; new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports); return exports;
}
const flat = node => Array.isArray(node) ? node.flatMap(flat) : node && typeof node === 'object' ? [node, ...flat(node.props?.children)] : [node];
const item = { id: 'lesson', title: 'Test lesson', description: 'The complete CMS preview.', format: 'article', authorName: 'Shelley', heroImageUrl: 'https://example.test/cover.jpg', creditCost: 2 };
const locked = { item, access: { hasPlus: false, credits: 3, unlockedIds: [] }, canRead: false, body: null, chapters: [] };
function screen(data = structuredClone(locked), error = null, request) {
  const state = [], refs = [], calls = []; let cursor = 0, refCursor = 0;
  const resource = { data, error, refresh: async () => {}, update: value => { resource.data = value; } };
  const jsx = (type, props) => ({ type, props });
  const modules = {
    '@/components/credits/TemporaryCreditButton': { TemporaryCreditButton: 'TemporaryCreditButton' },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: {
      useRef: value => refs[refCursor++] ??= { current: value },
      useState: initial => { const i = cursor++; if (!(i in state)) state[i] = initial; return [state[i], value => { state[i] = value; }]; },
    },
    'react-native': { Text: 'Text', View: 'View', ActivityIndicator: 'ActivityIndicator' },
    'expo-router': { router: { push: path => calls.push(path) } }, '@/lib/library': library,
    '@/hooks/useLibraryResource': { useLibraryResource: () => resource, libraryRequest: async (...args) => { calls.push(args); return request ? request(...args) : { ...locked, canRead: true, body: 'Paid content' }; } },
    './MarkdownBody': { MarkdownBody: 'MarkdownBody' }, './LibraryAttachments': { LibraryAttachments: 'LibraryAttachments' },
    './LibraryThumbnail': { LibraryThumbnail: 'LibraryThumbnail' },
  };
  for (const name of ['Button','Eyebrow','SectionTitle']) modules[`@/components/ui/${name}`] = { [name]: name };
  const { LibraryContent } = load(contentCode, modules);
  const render = () => { cursor = 0; refCursor = 0; return flat(LibraryContent({ itemId: 'lesson', preview: item })); };
  const button = title => render().find(node => node?.type === 'Button' && node.props.title === title);
  const press = title => { const target = button(title); assert.ok(target, title); assert.ok(!target.props.disabled, title); target.props.onPress(); };
  return { render, button, press, calls, resource };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
test('locked preview retains image, complete description and safe metadata while hiding all premium content', () => {
  const nodes = screen({ ...locked, body: 'Must remain hidden', chapters: [{ id: 'one', title: 'Secret' }] }).render();
  assert.ok(nodes.includes(item.description)); assert.ok(nodes.includes('Artikel · door Shelley'));
  assert.equal(nodes.find(node => node?.type === 'LibraryThumbnail').props.uri, item.heroImageUrl);
  assert.ok(!nodes.some(node => node?.type === 'MarkdownBody')); assert.ok(!nodes.includes('Secret'));
});
test('failed access lookup keeps public preview and retry without offering an unverified purchase', () => {
  const nodes = screen(null, 'Verbinding niet beschikbaar. Probeer opnieuw.').render();
  assert.ok(nodes.includes(item.title)); assert.ok(nodes.includes(item.description));
  assert.ok(nodes.some(node => node?.props?.title === 'Opnieuw proberen')); assert.ok(!nodes.includes('Ontgrendel dit item'));
});
test('insufficient credits disables purchase and displays the shortfall', () => {
  const page = screen({ ...locked, access: { ...locked.access, credits: 1 } });
  assert.equal(page.button('Ontgrendel voor 2 credits').props.disabled, true);
  assert.ok(page.render().includes('Je hebt nog 1 credit nodig om dit item te ontgrendelen.'));
  const button = page.render().find(node => node?.type === 'TemporaryCreditButton'); assert.ok(button); assert.equal(typeof button.props.onAdded, 'function'); assert.equal(page.calls.length, 0);
});
test('purchase requires confirmation, supports cancellation, ignores duplicate submits, then displays content', async () => {
  let finish;
  const page = screen(structuredClone(locked), null, () => new Promise(resolve => { finish = resolve; }));
  page.press('Ontgrendel voor 2 credits'); assert.equal(page.calls.length, 0);
  page.press('Annuleren'); assert.equal(page.calls.length, 0);
  page.press('Ontgrendel voor 2 credits');
  assert.ok(page.render().includes('Daarna heb je nog 1 credit.'));
  assert.ok(page.render().includes('Dit item blijft daarna ontgrendeld in je bibliotheek.'));
  const confirm = page.button('Bevestig: 2 credits gebruiken'); confirm.props.onPress(); confirm.props.onPress();
  assert.equal(page.calls.length, 1); assert.deepEqual(page.calls[0][3], { credits: 2 });
  finish({ ...locked, canRead: true, body: 'Paid content', access: { ...locked.access, credits: 1, unlockedIds: ['lesson'] } });
  await settle(); assert.equal(page.render().find(node => node?.type === 'MarkdownBody').props.markdown, 'Paid content');
  assert.ok(!page.render().includes('Ontgrendel dit item'));
});
test('failed purchase shows server error and permits retry without exposing content', async () => {
  const page = screen(structuredClone(locked), null, async () => { throw new Error('Het aantal benodigde credits is gewijzigd.'); });
  page.press('Ontgrendel voor 2 credits'); page.press('Bevestig: 2 credits gebruiken'); await settle();
  assert.ok(page.render().includes('Het aantal benodigde credits is gewijzigd.')); assert.ok(page.button('Ontgrendel voor 2 credits'));
  assert.ok(!page.render().some(node => node?.type === 'MarkdownBody'));
});
test('readable content bypasses purchase; locked Plus-only items offer Plus instead of credits', () => {
  for (const access of [{ ...locked.access }, { ...locked.access, hasPlus: true }, { ...locked.access, unlockedIds: ['lesson'] }]) {
    const page = screen({ ...locked, canRead: true, body: 'Readable', access });
    assert.ok(!page.render().includes('Ontgrendel dit item')); assert.ok(page.render().some(node => node?.type === 'MarkdownBody'));
  }
  const page = screen({ ...locked, item: { ...item, isPlus: true } });
  assert.ok(page.button('Bekijk Plus')); assert.ok(!page.button('Ontgrendel voor 2 credits'));
  page.press('Bekijk Plus'); assert.equal(page.calls[0], '/(tabs)/(pager)/protocol');
});
test('metadata omits invalid duration values and uses each actual content type', () => {
  for (const durationLabel of [undefined, null, '', 'undefined', 'null', '0 min', '0', '00:00']) {
    for (const format of ['article','video','audio','podcast']) assert.equal(library.libraryMetadata({ id: 'x', format, durationLabel, authorName: null }), library.libraryFormat(format));
  }
  assert.equal(library.libraryMetadata({ ...item, durationLabel: '6 min' }), 'Artikel · door Shelley');
  for (const format of ['video','audio','podcast']) assert.equal(library.libraryMetadata({ ...item, format, durationLabel: '6 min' }), `${library.libraryFormat(format)} · 6 min · door Shelley`);
});
function requests(getToken, session = { revision: 0 }) {
  return load(requestCode, { react: {}, 'expo-router': {}, '@/db/auth': { getApiBaseUrl: () => 'https://api.example.test' }, '@/db/connector': { getOrMintToken: getToken }, '@/db/provider': {}, '@/lib/account-session': { accountSession: session } }).libraryRequest;
}
test('library request renews an expired token once before retrying the original purchase', async t => {
  const tokens = [], sends = [];
  const request = requests(async (signal, force) => { assert.ok(signal instanceof AbortSignal); tokens.push(force); return force ? 'new' : 'old'; });
  t.mock.method(globalThis, 'fetch', async (url, init) => { sends.push(init); return new Response(JSON.stringify(sends.length === 1 ? { message: 'Invalid token' } : { canRead: true }), { status: sends.length === 1 ? 401 : 200 }); });
  assert.deepEqual(await request('/lesson/unlock', 'POST', undefined, { credits: 2 }), { canRead: true });
  assert.deepEqual(tokens, [false,true]); assert.equal(sends[1].headers.Authorization, 'Bearer new'); assert.equal(sends[1].body, '{"credits":2}');
});
test('network renewal errors stay distinct from missing credentials', async () => {
  await assert.rejects(requests(async () => { throw new Error('Verbinding niet beschikbaar. Probeer opnieuw.'); })('/lesson'), /Verbinding niet beschikbaar/);
  await assert.rejects(requests(async () => null)('/lesson'), /sessie is verlopen/);
});
test('previous account response cannot update the current library', async t => {
  const session = { revision: 0 };
  t.mock.method(globalThis, 'fetch', async () => { session.revision++; return new Response('{}'); });
  await assert.rejects(requests(async () => 'token', session)('/lesson'), /sessie is gewijzigd/);
});
test('native token renewal preserves credentials offline and clears only rejected credentials', async () => {
  let clears = 0, fail = new Error('Network request failed');
  const connector = load(connectorCode, {
    './auth': { loadCredentials: async () => ({ userId: 'owner' }), getApiBaseUrl: () => 'https://example.test', login: async () => { throw fail; }, clearCredentials: async () => { clears++; } },
    '@/lib/account-session': { accountSession: { revision: 0, forSession: async (_revision, action) => action() } },
  });
  await assert.rejects(connector.getOrMintToken(), /Verbinding niet beschikbaar/); assert.equal(clears, 0);
  fail = new Error('Login failed (401): Invalid credentials'); assert.equal(await connector.getOrMintToken(), null); assert.equal(clears, 1);
});

test('attachments are rendered only after confirmed parent access', () => {
  const attachments = [{ id: 'pdf', title: 'Checklist', name: 'check.pdf' }];
  assert.ok(!screen({ ...locked, attachments }).render().some(node => node?.type === 'LibraryAttachments'));
  const node = screen({ ...locked, canRead: true, attachments }).render().find(node => node?.type === 'LibraryAttachments');
  assert.deepEqual(node.props.attachments, attachments);
});
const cardCode = await compile('../components/library/LibraryCard.tsx');
test('overview cards show only the content type even when duration exists, including compact home cards', () => {
  const jsx = (type, props) => ({ type, props });
  const { LibraryCard } = load(cardCode, {
    'react/jsx-runtime': { jsx, jsxs: jsx }, 'react-native': { Pressable: 'Pressable', Text: 'Text', View: 'View' },
    'expo-router': { router: { push() {} } }, 'lucide-react-native': { BookOpen: 'BookOpen', Headphones: 'Headphones', LockKeyhole: 'LockKeyhole', Play: 'Play' },
    './LibraryThumbnail': { LibraryThumbnail: 'LibraryThumbnail' }, './LibraryBookmarkButton': { LibraryBookmarkButton: 'LibraryBookmarkButton' }, '@/lib/library': library,
  });
  for (const compact of [false, true]) {
    for (const format of ['video', 'article', 'audio', 'podcast']) {
      const nodes = flat(LibraryCard({ item: { ...item, format, durationLabel: '16 min' }, compact }));
      assert.ok(nodes.includes(library.libraryFormat(format)));
      assert.ok(!nodes.some(node => typeof node === 'string' && node.includes('16 min')));
    }
  }
});

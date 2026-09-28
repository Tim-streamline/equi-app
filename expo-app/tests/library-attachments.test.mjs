import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
const code = ts.transpileModule(await readFile(new URL('../components/library/LibraryAttachments.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
function screen(request = async () => ({ url: 'https://api.example.test/signed-pdf' }), attachments = [{ id: 'pdf', title: 'Checklist', name: 'check.pdf' }]) {
  const exports = {}, calls = [], opened = [], state = [], refs = [], session = { revision: 0 };
  let cursor = 0, refCursor = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useRef: value => refs[refCursor++] ??= { current: value }, useState: value => { const i = cursor++; state[i] ??= value; return [state[i], next => { state[i] = next; }]; } },
    'react-native': { Text: 'Text', View: 'View', Platform: { OS: 'android' }, Linking: { openURL: async url => opened.push(url) } },
    'lucide-react-native': { FileText: 'FileText' },
    '@/hooks/useLibraryResource': { libraryRequest: async (...args) => { calls.push(args); return request(session); } },
    '@/lib/account-session': { accountSession: session },
    '@/components/ui/Button': { Button: 'Button' }, '@/components/ui/SectionTitle': { SectionTitle: 'SectionTitle' },
  };
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const render = () => { cursor = refCursor = 0; return flatten(exports.LibraryAttachments({ itemId: 'lesson', attachments })); };
  const press = () => render().find(node => node?.type === 'Button').props.onPress();
  return { calls, opened, render, press };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
test('PDF action obtains authorized short-lived URL before opening viewer and suppresses duplicate taps', async () => {
  let finish; const page = screen(() => new Promise(resolve => { finish = resolve; }));
  assert.ok(page.render().includes('Checklist')); assert.ok(page.render().includes('PDF'));
  page.press(); page.press(); assert.deepEqual(page.calls, [['/lesson/attachments/pdf/open', 'POST']]); assert.deepEqual(page.opened, []);
  finish({ url: 'https://api.example.test/signed-pdf' }); await settle();
  assert.deepEqual(page.opened, ['https://api.example.test/signed-pdf']);
});
test('failed or previous-account PDF request never opens a viewer', async () => {
  const failure = screen(async () => { throw new Error('Geen toegang'); });
  failure.press(); await settle(); assert.deepEqual(failure.opened, []); assert.ok(failure.render().includes('Geen toegang'));
  const changed = screen(async session => { session.revision++; return { url: 'https://api.example.test/signed-pdf' }; });
  changed.press(); await settle(); assert.deepEqual(changed.opened, []);
});
test('items without PDFs omit the entire attachment section', () => {
  assert.deepEqual(screen(undefined, []).render(), [null]);
});

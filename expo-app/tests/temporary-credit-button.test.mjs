import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
const code = ts.transpileModule(await readFile(new URL('../components/credits/TemporaryCreditButton.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
function screen(request) {
  const exports = {}, state = [], refs = [], calls = []; let cursor = 0, refCursor = 0, refreshed = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx },
    react: { useRef: value => refs[refCursor++] ??= { current: value }, useState: value => { const i = cursor++; if (!(i in state)) state[i] = value; return [state[i], next => state[i] = next]; } },
    'react-native': { View: 'View', Text: 'Text' }, '@/components/ui/Button': { Button: 'Button' },
    '@/hooks/useLibraryResource': { libraryRequest: async (...args) => { calls.push(args); return request(); } },
  };
  new Function('require', 'exports', code)(name => modules[name], exports);
  const render = () => { cursor = refCursor = 0; return flatten(exports.TemporaryCreditButton({ onAdded: async () => { refreshed++; } })); };
  const button = () => render().find(node => node?.type === 'Button');
  return { render, button, calls, press: () => button().props.onPress(), get refreshed() { return refreshed; } };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
test('rapid taps send one grant, refresh the balance, and a later click uses a fresh key', async () => {
  let finish; const page = screen(() => new Promise(resolve => { finish = resolve; }));
  page.press(); page.press(); assert.equal(page.calls.length, 1); assert.equal(page.button().props.disabled, true);
  assert.deepEqual(page.calls[0].slice(0, 3), ['/credits/temporary-top-up', 'POST', undefined]);
  assert.match(page.calls[0][3].requestKey, /^[a-f0-9-]{36}$/);
  finish({ balance: 7 }); await settle(); assert.equal(page.refreshed, 1); assert.ok(page.render().includes('7 credits toegevoegd.'));
  page.press(); assert.notEqual(page.calls[0][3].requestKey, page.calls[1][3].requestKey); finish({ balance: 14 }); await settle();
  assert.equal(page.refreshed, 2);
});
test('uncertain failure retains the key for retry and never claims a successful grant', async () => {
  const page = screen(async () => { throw new Error('Offline'); });
  page.press(); await settle(); assert.ok(page.render().includes('Offline')); assert.equal(page.refreshed, 0);
  page.press(); await settle(); assert.equal(page.calls[0][3].requestKey, page.calls[1][3].requestKey);
});

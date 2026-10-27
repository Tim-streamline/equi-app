import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as memory from '../lib/intake/scroll-memory.ts';

const jsx = (type, props) => ({ type, props });
function load(path, modules) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}
// Minimal hook runtime: state and refs persist between renders, effects run on request.
function hooks() {
  const state = [], refs = [], effects = [];
  let s = 0, r = 0;
  return {
    react: {
      useState: initial => { const k = s++; if (!(k in state)) state[k] = initial; return [state[k], v => { state[k] = typeof v === 'function' ? v(state[k]) : v; }]; },
      useRef: initial => { const k = r++; if (!(k in refs)) refs[k] = { current: initial }; return refs[k]; },
      useEffect: effect => effects.push(effect),
      useCallback: fn => fn,
    },
    render(component, props) { s = 0; r = 0; effects.length = 0; return component(props); },
    runEffects() { for (const effect of effects.splice(0)) effect(); },
  };
}

test('a recent position on a page is remembered, an old one is not', () => {
  memory.rememberScroll('b:paard', 840, 1000);
  assert.equal(memory.rememberedScroll('b:paard', 1000 + 60_000), 840);
  assert.equal(memory.rememberedScroll('b:paard', 1000 + memory.SCROLL_MEMORY_MS + 1), null);
  assert.equal(memory.rememberedScroll('b:voer', 1000), null);
  memory.rememberScroll('b:top', 0, 1000);
  assert.equal(memory.rememberedScroll('b:top', 1000), null, 'the top of the page needs no restore');
});

test('a rebuilt page returns to where the owner was typing instead of the top', () => {
  memory.rememberScroll('b:paard', 1200);
  const h = hooks();
  const { IntakeScrollView } = load('../components/intake/IntakeScrollView.tsx', {
    'react/jsx-runtime': { jsx, jsxs: jsx }, react: h.react,
    '../ui/KeyboardForm': { KeyboardViewport: 'Viewport', KeyboardScrollView: 'Scroll' },
    '@/lib/intake/scroll-memory': memory, './IntakeInputFrame': {},
  });
  const tree = h.render(IntakeScrollView, { memoryKey: 'b:paard', children: 'fields' });
  const scroll = tree.props.children[0];
  const calls = [];
  scroll.props.ref({ scrollTo: options => calls.push(options) });
  scroll.props.onScroll({ nativeEvent: { contentOffset: { y: 0 } } });
  scroll.props.onContentSizeChange(400, 600);
  assert.deepEqual(calls, [], 'wait until the page is tall enough');
  scroll.props.onContentSizeChange(400, 2400);
  assert.deepEqual(calls, [{ y: 1200, animated: false }]);
  scroll.props.onScroll({ nativeEvent: { contentOffset: { y: 1350 } } });
  assert.equal(memory.rememberedScroll('b:paard'), 1350);
});

test('a short sync hiccup keeps the open intake on screen instead of rebuilding it', () => {
  const h = hooks();
  let loaded = true, bookingId = 'booking', horseId = 'horse';
  const timers = [];
  const realTimeout = globalThis.setTimeout;
  globalThis.setTimeout = fn => { timers.push(fn); return timers.length; };
  try {
    const { default: Layout } = load('../app/intake/_layout.tsx', {
      'react/jsx-runtime': { jsx, jsxs: jsx }, react: h.react,
      'react-native': { Text: 'Text', View: 'View', ActivityIndicator: 'ActivityIndicator' },
      '@/components/ui/Button': { Button: 'Button' },
      '@/hooks/useHorseDashboard': { dashboardRequest: async () => ({}) },
      '@powersync/react': { useStatus: () => ({ hasSynced: true }), useQuery: () => ({ data: [{ id: 'horse' }], isLoading: false }) },
      '@/db/hooks': { useCurrentUserId: () => 'user' },
      'expo-router': { Redirect: 'Redirect', Stack: 'Stack', useSegments: () => ['intake', 'section', '[id]'], router: {} },
      '@/lib/intake/store': { useIntake: () => ({ loaded, horseId, bookingId, state: { submittedAt: null } }) },
    });
    assert.equal(h.render(Layout).type, 'Stack');
    h.runEffects();
    bookingId = null;
    assert.equal(h.render(Layout).type, 'Stack', 'the page stays while the booking reappears');
    loaded = false;
    assert.equal(h.render(Layout).type, 'Stack');
    h.runEffects();
    assert.equal(timers.length, 1);
    timers[0]();
    assert.notEqual(h.render(Layout)?.type, 'Stack', 'a booking that stays away is handled as before');
    loaded = true; bookingId = 'booking';
    assert.equal(h.render(Layout).type, 'Stack');
  } finally {
    globalThis.setTimeout = realTimeout;
  }
});

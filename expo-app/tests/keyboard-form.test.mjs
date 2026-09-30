import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

function harness(platform) {
  const env = { context: null, focused: null, hooks: new Map(), listeners: {}, cleanups: [], frames: new Map(), key: '', index: 0 };
  const jsx = (type, props) => ({ type, props });
  const slot = initial => { const slots = env.hooks.get(env.key); const i = env.index++; return [slots[i] ??= { value: initial }, i]; };
  const react = {
    createContext: () => ({ Provider: 'Provider' }), useContext: () => env.context,
    useRef: value => slot({ current: value })[0].value,
    useState: initial => { const [s] = slot(initial); return [s.value, v => { s.value = typeof v === 'function' ? v(s.value) : v; }]; },
    useCallback: fn => slot(fn)[0].value,
    useEffect: fn => { const [s] = slot(false); if (!s.value) { s.value = true; env.cleanups.push(fn()); } },
  };
  const native = { KeyboardAvoidingView: 'KeyboardAvoidingView', ScrollView: 'ScrollView', View: 'View', Platform: { OS: platform },
    TextInput: Object.assign(() => {}, { State: { currentlyFocusedInput: () => env.focused } }),
    Keyboard: { addListener: (name, fn) => { (env.listeners[name] ??= new Set()).add(fn); return { remove: () => env.listeners[name].delete(fn) }; } },
  };
  const modules = { react, 'react/jsx-runtime': { jsx, jsxs: jsx }, 'react-native': native };
  const exports = {};
  new Function('require', 'exports', 'requestAnimationFrame', 'cancelAnimationFrame', ts.transpileModule(readFileSync(new URL('../components/ui/KeyboardForm.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText)(name => modules[name], exports,
    fn => { const id = Math.random(); env.frames.set(id, fn); return id; }, id => env.frames.delete(id));
  return {
    env, exports,
    render(name, props = {}, instance = name) { env.key = instance; env.index = 0; if (!env.hooks.has(instance)) env.hooks.set(instance, []); return exports[name](props); },
    flush() { const frames = [...env.frames.values()]; env.frames.clear(); frames.forEach(fn => fn()); },
    emit(name, screenY) { for (const fn of env.listeners[name] ?? []) fn({ endCoordinates: { screenY } }); },
    close() { env.cleanups.forEach(fn => fn?.()); },
  };
}

for (const platform of ['android', 'ios']) {
  for (const screenHeight of [568, 667, 844]) {
    test(`${platform} ${screenHeight}px: short forms/modals reveal full input, allow scrolling and restore after keyboard hide`, () => {
      const h = harness(platform);
      let origin = 88;
      let root = h.render('KeyboardViewport', { style: { flex: 1 } });
      root.props.ref.current = { measureInWindow: fn => fn(0, origin, 360, screenHeight - origin) };
      root.props.onLayout(); root = h.render('KeyboardViewport', { style: { flex: 1 } });
      const avoiding = root.props.children;
      assert.equal(avoiding.props.behavior, platform === 'ios' ? 'padding' : 'height');
      assert.equal(avoiding.props.keyboardVerticalOffset, origin);
      let viewportHeight = screenHeight - origin, inputTop = 440, inputHeight = 130, offset = 200;
      const scrolls = [];
      const viewport = { getNativeScrollRef: () => ({ measureInWindow: fn => fn(0, origin, 360, viewportHeight) }), scrollTo: options => { inputTop -= options.y - offset; offset = options.y; scroll.props.onScroll({ nativeEvent: { contentOffset: { y: offset } } }); scrolls.push(options); } };
      const forwardedScroll = { current: null }, forwardedInput = { current: null };
      let tree = h.render('KeyboardScrollView', { ref: forwardedScroll });
      h.env.context = tree.props.value; let scroll = tree.props.children;
      scroll.props.ref(viewport); assert.equal(forwardedScroll.current, viewport);
      scroll.props.onScroll({ nativeEvent: { contentOffset: { y: offset } } });
      const nativeInput = { measureInWindow: fn => fn(0, inputTop, 300, inputHeight) };
      let input = h.render('KeyboardTextInput', { ref: forwardedInput, multiline: true });
      input.props.ref(nativeInput); assert.equal(forwardedInput.current, nativeInput);
      input.props.onLayout({ nativeEvent: { layout: { height: 130 } } });
      h.env.focused = nativeInput;
      input.props.onFocus({}); h.flush();
      // The viewport shrinks independently of keyboard height, e.g. a header/footer or bottom sheet.
      const keyboardTop = screenHeight - 310;
      viewportHeight = keyboardTop - origin - 40;
      scroll.props.onLayout({ nativeEvent: { layout: { height: viewportHeight } } });
      tree = h.render('KeyboardScrollView', { ref: forwardedScroll }); h.env.context = tree.props.value; scroll = tree.props.children;
      input = h.render('KeyboardTextInput', { ref: forwardedInput, multiline: true });
      inputHeight = Math.min(130, input.props.style[1].maxHeight);
      h.emit('keyboardDidShow', keyboardTop); h.flush();
      assert.ok(inputTop >= origin + 11, `${inputTop} >= ${origin + 11}`);
      assert.ok(inputTop + inputHeight <= origin + viewportHeight - 15);
      assert.equal(scroll.props.keyboardShouldPersistTaps, 'handled');
      assert.equal(scroll.props.keyboardDismissMode, 'none');
      // Manual scrolling remains possible; closing the keyboard must not reset it.
      const count = scrolls.length;
      scroll.props.onScroll({ nativeEvent: { contentOffset: { y: 420 } } });
      h.emit('keyboardDidHide');
      viewportHeight = screenHeight - origin;
      scroll.props.onLayout({ nativeEvent: { layout: { height: viewportHeight } } }); h.flush();
      assert.equal(scrolls.length, count);
      tree = h.render('KeyboardScrollView', { ref: forwardedScroll }); h.env.context = tree.props.value;
      input = h.render('KeyboardTextInput', { ref: forwardedInput, multiline: true });
      assert.equal(input.props.style[1].minHeight, undefined, 'original multiline minimum is restored');
      assert.ok(input.props.style[1].maxHeight > 130);
      h.close(); assert.ok(Object.values(h.env.listeners).every(set => set.size === 0));
    });
  }
}

test('opening a modal does not scroll a previously focused background form', () => {
  const h = harness('android'); let backgroundScrolls = 0;
  const first = { measureInWindow: fn => fn(0, 650, 300, 70) };
  const second = { measureInWindow: fn => fn(0, 500, 300, 70) };
  const tree = h.render('KeyboardScrollView'); h.env.context = tree.props.value;
  tree.props.children.props.ref({ getNativeScrollRef: () => ({ measureInWindow: fn => fn(0, 0, 360, 400) }), scrollTo: () => { backgroundScrolls++; } });
  const input = h.render('KeyboardTextInput'); input.props.ref(first); h.env.focused = first; input.props.onFocus({}); h.flush();
  const before = backgroundScrolls; h.env.focused = second;
  h.emit('keyboardDidShow', 400); h.flush();
  assert.equal(backgroundScrolls, before); h.close();
});

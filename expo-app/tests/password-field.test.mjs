import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../components/ui/Field.tsx', import.meta.url), 'utf8');
const code = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flat = node => !node || typeof node !== 'object' ? [] : [node, ...[node.props?.children].flat(Infinity).flatMap(flat)];
function field(label) {
  const states = [], refs = [];
  let index = 0, refIndex = 0;
  const modules = {
    react: {
      useRef: value => refs[refIndex++] ??= { current: value },
      useState: value => { const i = index++; if (!(i in states)) states[i] = value; return [states[i], next => { states[i] = typeof next === 'function' ? next(states[i]) : next; }]; },
      useLayoutEffect() {},
    },
    'react/jsx-runtime': { jsx: (type, props) => ({ type, props }), jsxs: (type, props) => ({ type, props }) },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable', Platform: { OS: 'android' } },
    'lucide-react-native': { Eye: 'Eye', EyeOff: 'EyeOff' },
    '@/components/ui/KeyboardForm': { KeyboardTextInput: 'TextInput' },
  };
  const exports = {};
  new Function('require', 'exports', code)(name => modules[name], exports);
  return (props = {}) => {
    index = 0; refIndex = 0;
    const nodes = flat(exports.Field({ label, value: 'Keep-this-password', secureTextEntry: true, passwordToggle: true, ...props }));
    return { input: nodes.find(n => n.type === 'TextInput'), button: nodes.find(n => n.type === 'Pressable') };
  };
}

test('password fields toggle independently, preserve their value and restore the selected range', () => {
  const password = field('Wachtwoord'), confirmation = field('Herhaal wachtwoord');
  assert.equal(password().input.props.secureTextEntry, true);
  password().input.props.onSelectionChange({ nativeEvent: { selection: { start: 2, end: 6 } } });
  password().button.props.onPress();
  assert.equal(password().input.props.secureTextEntry, false);
  assert.equal(password().input.props.value, 'Keep-this-password');
  assert.deepEqual(password().input.props.selection, { start: 2, end: 6 });
  assert.equal(password().button.props.accessibilityLabel, 'Wachtwoord verbergen');
  assert.equal(confirmation().input.props.secureTextEntry, true);
  confirmation().button.props.onPress();
  password().button.props.onPress();
  assert.equal(password().input.props.secureTextEntry, true);
  assert.equal(confirmation().input.props.secureTextEntry, false);
  assert.equal(password({ editable: false }).button.props.disabled, true);
  assert.equal(password({ passwordToggle: false }).button, undefined);
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as presentation from '../lib/scanner-presentation.ts';

function evaluate(code, modules) {
  const exports = {};
  new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, `Unexpected dependency: ${name}`);
    return modules[name];
  }, exports);
  return exports;
}

// Exercise the installed NativeWind JSX wrapper, including its native Pressable mapping.
const wrapJSX = evaluate(readFileSync(new URL('../node_modules/react-native-css-interop/dist/runtime/wrap-jsx.js', import.meta.url), 'utf8'), {
  './api': { interopComponents: new Map([['Pressable', 'InteropPressable']]) },
  './third-party-libs/react-native-safe-area-context': { maybeHijackSafeAreaProvider: type => type },
  './components': {},
}).default;
const jsx = wrapJSX((type, props) => ({ type, props }));
const code = ts.transpileModule(readFileSync(new URL('../components/scanner/ScannerUI.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, jsxImportSource: 'nativewind', module: ts.ModuleKind.CommonJS },
}).outputText;
const { RoundButton, ScannerButton, ScanRow } = evaluate(code, {
  'nativewind/jsx-runtime': { jsx, jsxs: jsx },
  'react-native': { Pressable: 'Pressable', View: 'View', Text: 'Text', ScrollView: 'ScrollView', StyleSheet: { create: styles => styles } },
  'react-native-safe-area-context': {},
  'expo-status-bar': {},
  'expo-router': { router: {} },
  'lucide-react-native': {},
  '@/lib/scanner-presentation': presentation,
});

function style(control, pressed = false) {
  assert.equal(control.type, 'Pressable', 'Native Pressable must receive its style callback directly');
  return Object.assign({}, ...control.props.style({ pressed }));
}

test('scanner action buttons retain layout, colors, and press feedback on native', () => {
  const camera = ScannerButton({ title: 'Camera', variant: 'white' });
  assert.equal(style(camera).backgroundColor, '#fff');
  assert.equal(style(camera).minHeight, 52);
  assert.equal(style(camera).flexDirection, 'row');
  assert.equal(style(camera, true).opacity, 0.75);
  const upload = ScannerButton({ title: 'Uploaden', variant: 'dark-outline' });
  assert.equal(style(upload).borderWidth, 1);
  assert.equal(style(upload).paddingHorizontal, 15);
  const disabled = ScannerButton({ title: 'Verder', disabled: true });
  assert.equal(disabled.props.disabled, true);
  assert.equal(style(disabled, true).opacity, 0.5);
});

test('scanner header controls and history rows retain their native layout', () => {
  const history = RoundButton({ label: 'Scanhistorie', onPress() {} });
  assert.equal(style(history).width, 44);
  assert.equal(style(history).height, 44);
  assert.equal(style(history, true).opacity, 0.6);
  const row = ScanRow({ scan: { id: 'scan-1', productName: 'Voer' } });
  assert.equal(style(row).flexDirection, 'row');
  assert.equal(style(row).borderRadius, 16);
  assert.equal(style(row, true).opacity, 0.7);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function harness(platform) {
  let focused = false;
  let context = null;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react: { createContext: () => ({ Provider: 'Provider' }), useContext: () => context, useState: () => [focused, value => { focused = value; }] },
    'react/jsx-runtime': { jsx, jsxs: jsx }, 'react-native': { View: 'View', Platform: { OS: platform } },
    '../ui/KeyboardForm': { KeyboardTextInput: 'KeyboardTextInput' },
  };
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL('../components/intake/IntakeInputFrame.tsx', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  new Function('require', 'exports', js)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return { exports, frame: () => { const result = exports.IntakeInputFrame({ children: 'Input and unit', radius: 8 }); context = result.props.value; return result.props.children; } };
}

test('shared intake frame forwards input events and refs while owning the native focus boundary', () => {
  const h = harness('android');
  const frame = h.frame();
  assert.equal(frame.props.dataSet.intakeInputFrame, 'true');
  assert.equal(frame.props.children[1], false);
  const events = [];
  const ref = { current: null };
  const field = h.exports.IntakeTextInput({ ref, value: '12', onFocus: () => events.push('focus'), onBlur: () => events.push('blur'), onChangeText: v => events.push(v) });
  assert.equal(field.props.ref, ref);
  assert.equal(field.props.value, '12');
  assert.equal(field.props.underlineColorAndroid, 'transparent');
  field.props.onFocus({});
  const ring = h.frame().props.children[1];
  assert.equal(ring.props.pointerEvents, 'none');
  assert.equal(ring.props.accessible, false);
  assert.equal(ring.props.style.borderRadius, 12);
  field.props.onChangeText('14');
  field.props.onBlur({});
  assert.equal(h.frame().props.children[1], false);
  assert.deepEqual(events, ['focus', '14', 'blur']);
});

test('web uses focus-within on the full boundary and suppresses only internal rings', () => {
  const h = harness('web');
  h.frame();
  h.exports.IntakeTextInput({}).props.onFocus({});
  assert.equal(h.frame().props.children[1], false);
  for (const path of ['../../web/global.css', '../global.css']) {
    const css = readFileSync(new URL(path, import.meta.url), 'utf8');
    assert.match(css, /\[data-intake-input-frame\]:focus-within\s*\{[^}]*outline:\s*2px solid #18BAB0/);
    assert.match(css, /\[data-intake-input-frame\] :focus\s*\{[^}]*outline:\s*none;[^}]*box-shadow:\s*none/);
  }
});

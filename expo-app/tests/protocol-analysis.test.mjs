import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import { protocolStyleModules } from './protocol-styles.mjs';

const code = ts.transpileModule(readFileSync(new URL('../components/protocol/ProtocolAnalysis.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;
const jsx = (type, props) => ({ type, props });
const exports = {};
new Function('require', 'exports', code)(name => ({
  'react/jsx-runtime': { jsx, jsxs: jsx },
  ...protocolStyleModules,
  'react-native': { Text: 'Text', View: 'View' },
})[name], exports);
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
const base = { summary: 'Persoonlijke analyse.', priorities: [], observations: ['Eerste zin. Tweede zin.'] };

test('authored expectation and follow-up render after observations with line breaks preserved', () => {
  const expectationFollowUp = 'Evaluatie na twee weken.\nDaarna bespreken we het vervolg.';
  const tree = flatten(exports.ProtocolAnalysis({ analysis: { ...base, expectationFollowUp } }));
  assert.ok(tree.includes('Verwachting en vervolg'));
  assert.ok(tree.includes(expectationFollowUp));
  assert.ok(tree.indexOf(expectationFollowUp) > tree.indexOf(base.observations[0]));
});

test('older cached analyses without the optional follow-up field still render', () => {
  const tree = flatten(exports.ProtocolAnalysis({ analysis: base }));
  assert.ok(tree.includes(base.summary));
  assert.ok(!tree.includes('Verwachting en vervolg'));
});

test('long texts read as articles with a real heading, one paragraph per blank line, and no focus section', () => {
  const summary = 'Eerste alinea.\n\nTweede alinea.';
  const tree = flatten(exports.ProtocolAnalysis({ analysis: { ...base, summary, priorities: [{ id: 'f', title: 'Oude focus', body: 'Niet tonen' }] } }));
  assert.ok(tree.includes('Eerste alinea.'));
  assert.ok(tree.includes('Tweede alinea.'));
  const heading = tree.find(n => n?.props?.accessibilityRole === 'header' && n.props.children === 'Persoonlijke analyse');
  assert.ok(heading, 'the analysis has its own heading');
  assert.ok(!tree.includes('Focus van het protocol'));
  assert.ok(!tree.includes('Oude focus'));
});

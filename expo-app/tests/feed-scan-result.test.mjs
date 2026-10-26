import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const jsx = (type, props) => ({ type, props });
const code = ts.transpileModule(readFileSync(new URL('../components/scanner/FeedScanResult.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const exports = {};
const modules = { 'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' }, 'react-native': { Text: 'Text', View: 'View' }, './ScannerUI': { C: {}, s: {}, ScanScore: 'ScanScore' }, './ScannerAnalysisStates': { AnalyticalConstituents: 'Analysis', FindingsCard: 'Findings', MissingDataCard: 'Missing' } };
new Function('require', 'exports', code)(name => { assert.ok(name in modules); return modules[name]; }, exports);
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
const base = { name: 'Ingezonden product', brand: 'Merk', provisional: true, score_cap: null, missing_data: [], findings: [], ingredients: [], nutrients: [], message: 'Ter beoordeling' };

test('provisional zero and unknown scores stay distinct, with no fabricated default score', () => {
  for (const [score, expected] of [['0', 0], [null, null]]) {
    const nodes = flatten(exports.FeedScanResult({ result: { ...base, score } }));
    assert.equal(nodes.find(n => n?.type === 'ScanScore').props.value, expected);
    assert.ok(nodes.includes('Voorlopige beoordeling'));
    assert.equal(nodes.includes('Nog geen rapportcijfer'), score === null);
  }
});

test('unknown analysis basis and limits remain visible without changing the supplied value', () => {
  const nodes = flatten(exports.FeedScanResult({ result: { ...base, score: null, nutrients: [{ code: 'sugar', value: '6', unit: '%', qualifier: 'less_than', basis: 'unknown' }] } }));
  const row = nodes.find(n => n?.type === 'Analysis').props.rows[0];
  assert.equal(row.value, '< 6 %');
  assert.match(row.note, /Analysebasis niet bekend/);
});

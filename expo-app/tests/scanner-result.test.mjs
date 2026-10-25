import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as presentation from '../lib/scanner-presentation.ts';

function load(path, modules) {
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
  }).outputText;
  const exports = {};
  new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, `Unexpected dependency: ${name}`);
    return modules[name];
  }, exports);
  return exports;
}

function renderScan(rows, id) {
  const react = { useMemo: fn => fn(), useState: () => [true, () => {}] };
  const hooks = load('../db/hooks.ts', {
    react,
    '@powersync/react': { useQuery: (sql, params) => ({ data: sql.includes('scan_ingredients')
      ? [{ id: 'ingredient-1', scan_id: 'scan-1', name: 'Timothee', tag: 'good' }]
      : rows.filter(row => row.id === params[0]) }) },
    './app-strings': {}, './ids': {}, '@/db/provider': {},
    '@/lib/protocol-plan': {}, '@/lib/protocol-advice': {},
  });
  const jsx = (type, props) => ({ type, props });
  const screen = load('../app/(tabs)/scanner/result.tsx', {
    react,
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable' },
    'expo-router': { useLocalSearchParams: () => ({ id }), router: {} },
    'lucide-react-native': {},
    '@/db/hooks': hooks,
    '@/lib/scanner-presentation': presentation,
    '@/components/scanner/ScannerUI': { C: {}, s: {}, ScanScore: 'ScanScore' },
  });
  const flatten = node => Array.isArray(node) ? node.flatMap(flatten)
    : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
  return flatten(screen.default());
}

test('saved scans retain their score, advice, and ingredients without a product name', () => {
  for (const product_name of [null, '', 'Timothee balancer']) {
    const nodes = renderScan([{ id: 'scan-1', product_name, score: 62, advice: 'Opgeslagen advies' }], 'scan-1');
    assert.ok(!nodes.includes('Geen analyse gevonden'));
    assert.ok(nodes.includes(product_name || 'Voeranalyse'));
    assert.ok(nodes.includes('Opgeslagen advies'));
    assert.ok(nodes.includes('Timothee'));
    assert.equal(nodes.find(node => node?.type === 'ScanScore').props.value, 62);
  }
});

test('missing scan IDs and absent records still show the unavailable result', () => {
  for (const id of ['missing', undefined]) {
    const nodes = renderScan([], id);
    assert.ok(nodes.includes('Geen analyse gevonden'));
    assert.ok(!nodes.some(node => node?.type === 'ScanScore'));
  }
});

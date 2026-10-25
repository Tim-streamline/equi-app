import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const jsx = (type, props) => ({ type, props });
const framework = {
  react: { useState: () => [true, () => {}] },
  'react/jsx-runtime': { jsx, jsxs: jsx },
  'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable', Platform: { OS: 'web' } },
  'lucide-react-native': {},
  './ScannerUI': { C: {}, s: {}, ScannerButton: 'ScannerButton' },
};
function load(file, modules = framework) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
  }).outputText;
  new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, `Unexpected dependency: ${name}`);
    return modules[name];
  }, exports);
  return exports;
}
const flatten = node => Array.isArray(node) ? node.flatMap(flatten)
  : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];

test('unread and undeclared nutrients remain distinct and never acquire invented zero values', () => {
  const { AnalyticalConstituents, SugarStarchCard } = load('../components/scanner/ScannerAnalysisStates.tsx');
  const rows = flatten(AnalyticalConstituents({ rows: [
    { label: 'Suiker', availability: 'not_declared' }, { label: 'Zetmeel', availability: 'unread' },
  ] }));
  assert.ok(rows.includes('Niet vermeld'));
  assert.ok(rows.includes('Niet herkend'));
  for (const availability of ['unknown', 'unread', 'not_declared']) {
    const content = flatten(SugarStarchCard({ availability })).filter(value => typeof value === 'string').join(' ');
    assert.doesNotMatch(content, /0%|laag zetmeel|maximaal 10/);
  }
});

test('photo upload state shows only supplied progress and never promises stored files after an error', () => {
  const { ScannerPhotoCard } = load('../components/scanner/ScannerPhotoCard.tsx');
  const base = { title: 'Analyse', detail: 'Etiketfoto', required: true };
  const unknown = flatten(ScannerPhotoCard({ ...base, phase: 'uploading' }));
  assert.ok(unknown.includes('Uploaden…'));
  assert.ok(!unknown.includes('Uploaden… 0%'));
  const progress = flatten(ScannerPhotoCard({ ...base, phase: 'uploading', progress: 43 }));
  assert.ok(progress.includes('Uploaden… 43%'));
  const error = flatten(ScannerPhotoCard({ ...base, phase: 'error' }));
  assert.ok(error.includes('Uploaden mislukt'));
  assert.ok(!error.some(value => typeof value === 'string' && /bewaard|geüpload/i.test(value)));
});

test('the design inspector redirects without loading data unless explicitly enabled in the build', () => {
  const previous = process.env.EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW;
  try {
    delete process.env.EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW;
    const { default: Preview } = load('../app/(tabs)/scanner/design-preview.tsx', {
      ...framework,
      'expo-router': { Redirect: 'Redirect' },
      '@/db/hooks': { useScanResults: () => { throw new Error('Must not load preview data'); } },
      '@/lib/scanner-presentation': {},
      '@/components/scanner/ScannerUI': {},
      '@/components/scanner/ScannerPhotoCard': {},
      '@/components/scanner/ScannerAnalysisStates': {},
      '@/components/scanner/ScannerProcessingStates': {},
    });
    assert.equal(Preview().type, 'Redirect');
    assert.equal(Preview().props.href, '/(tabs)/scanner');
  } finally {
    if (previous === undefined) delete process.env.EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW;
    else process.env.EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW = previous;
  }
});

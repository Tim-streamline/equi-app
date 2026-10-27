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
  '@/db/auth': {},
  '@/db/connector': {},
  '@/lib/account-session': { accountSession: { revision: 0 } },
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

test('resumed scanner photos load the real private image and revoke their browser preview on unmount', async () => {
  const nativeFetch = globalThis.fetch;
  const createUrl = URL.createObjectURL;
  const revokeUrl = URL.revokeObjectURL;
  const requests = [], revoked = [], effects = [], states = [];
  let cursor = 0;
  const modules = {
    ...framework,
    react: {
      useEffect: effect => effects.push(effect),
      useState: initial => { const index = cursor++; if (!(index in states)) states[index] = initial; return [states[index], next => states[index] = typeof next === 'function' ? next(states[index]) : next]; },
    },
    'react-native': { ...framework['react-native'], Image: 'Image', ActivityIndicator: 'ActivityIndicator' },
    '@/db/auth': { getApiBaseUrl: () => 'https://api.example.test' },
    '@/db/connector': { getOrMintToken: async () => 'test-token' },
  };
  try {
    globalThis.fetch = async (url, options) => { requests.push({url, options}); return { ok: true, status: 200, blob: async () => new Blob(['actual photo'], {type:'image/jpeg'}) }; };
    URL.createObjectURL = () => 'blob:uploaded-photo';
    URL.revokeObjectURL = url => revoked.push(url);
    const { ScannerPhotoCard } = load('../components/scanner/ScannerPhotoCard.tsx', modules);
    const preview = flatten(ScannerPhotoCard({ title: 'Voorkant', detail: '', submissionId: 'submission', photoId: 'saved-photo' })).find(node => typeof node?.type === 'function' && node.type.name === 'ScannerPhotoPreview');
    const draw = () => { cursor = 0; return preview.type(preview.props); };
    draw();
    const cleanup = effects.shift()();
    await new Promise(resolve => setImmediate(resolve));
    const image = draw();
    assert.equal(image.type, 'Image');
    assert.equal(image.props.source.uri, 'blob:uploaded-photo');
    assert.equal(image.props.style.width, 96);
    assert.equal(image.props.style.height, 96);
    assert.equal(requests[0].url, 'https://api.example.test/api/feed-scanner/submissions/submission/photos/saved-photo');
    assert.equal(requests[0].options.headers.Authorization, 'Bearer test-token');
    cleanup();
    assert.deepEqual(revoked, ['blob:uploaded-photo']);
    const local = { ...preview.props, uri: 'file:///selected-photo.jpg' };
    cursor = 0;
    assert.equal(preview.type(local).props.source.uri, local.uri, 'a newly selected photo takes priority over a saved preview');
  } finally {
    globalThis.fetch = nativeFetch;
    URL.createObjectURL = createUrl;
    URL.revokeObjectURL = revokeUrl;
  }
});

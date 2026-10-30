import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const code = ts.transpileModule(readFileSync(new URL('../app/(tabs)/scanner/processing.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;

async function openProcessing(status, start) {
  const calls = [];
  const navigation = [];
  const effects = [];
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react: { useState: initial => [initial, () => {}], useEffect: effect => effects.push(effect) },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { Text: 'Text', View: 'View' },
    'expo-router': {
      useLocalSearchParams: () => ({ id: 'submission', start, name: 'Mijn voer' }),
      router: { replace: target => navigation.push(target) },
    },
    '@/db/hooks': { useCurrentUserId: () => 'owner' },
    '@/lib/haptics': { successHaptic: () => {} },
    '@/components/scanner/ScannerUI': { ScannerFrame: 'Frame', ScannerButton: 'Button', ScannerStepProgress: 'Progress', s: {} },
    '@/components/scanner/ScannerProcessingStates': { ScannerProcessingState: 'Processing' },
    '@/lib/feed-scanner-api': {
      scannerRequest: async (path, body) => {
        calls.push({ path, body });
        return body
          ? { id: 'submission', status: 'completed', scan_result_id: 'result', workflow_version: 2 }
          : { id: 'submission', status, workflow_version: 2 };
      },
    },
  };
  const exports = {};
  new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, `Unexpected import: ${name}`);
    return modules[name];
  }, exports);
  exports.default();
  const cleanup = effects[0]();
  await new Promise(resolve => setImmediate(resolve));
  cleanup();
  return { calls, navigation };
}

test('starting a resumed failed submission retries analysis and opens the result', async () => {
  const { calls, navigation } = await openProcessing('failed', '1');
  assert.deepEqual(calls, [
    { path: '/submissions/submission', body: undefined },
    { path: '/submissions/submission/analyse', body: { name: 'Mijn voer' } },
  ]);
  assert.deepEqual(navigation, [{ pathname: '/(tabs)/scanner/result', params: { id: 'result' } }]);
});

test('viewing a failed submission without starting does not retry paid analysis', async () => {
  const { calls } = await openProcessing('failed');
  assert.deepEqual(calls, [{ path: '/submissions/submission', body: undefined }]);
});

test('an explicit start does not restart an active or completed submission', async () => {
  for (const status of ['processing', 'completed', 'review_required']) {
    const { calls } = await openProcessing(status, '1');
    assert.deepEqual(calls, [{ path: '/submissions/submission', body: undefined }]);
  }
});

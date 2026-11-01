import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

// Execute the screen's "open this submission" effect with only its collaborators mocked.
const source = readFileSync(new URL('../app/(tabs)/(pager)/scan.tsx', import.meta.url), 'utf8');
const start = source.indexOf('  useEffect(() => {\n    if (!params.submissionId');
const end = source.indexOf('}, [params.submissionId, params.t, userId]);', start);
const effect = ts.transpileModule(source.slice(start, end) + '}, []);', { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;

async function open(current) {
  const events = [];
  const context = vm.createContext({
    params: { submissionId: current.id }, userId: 'owner', submission: { current: { id: 'earlier-draft' } }, uploadedAssets: { current: {} },
    useEffect: fn => fn(), scannerRequest: async () => current, resetScan: () => events.push('reset'),
    router: { push: route => events.push(route.pathname) },
    setError() {}, setRecognition() {}, setPhotos() {}, setPhotoStep() {}, setName() {}, setScreen: screen => events.push(`screen:${screen}`), setSubmitted() {},
    resumePhotoStep: () => 'analysis',
  });
  vm.runInContext(effect, context);
  await new Promise(resolve => setImmediate(resolve));
  return { events, submission: context.submission.current };
}

test('opening a finished or running submission leaves no earlier scan behind on this page', async () => {
  assert.deepEqual((await open({ id: 'done', status: 'completed', scan_result_id: 'result' })).events, ['reset', '/(tabs)/scanner/result']);
  for (const status of ['checking', 'processing', 'review_required']) {
    assert.deepEqual((await open({ id: 'running', status })).events, ['reset', '/(tabs)/scanner/processing']);
  }
});

test('a submission that needs photos continues on this page', async () => {
  const { events, submission } = await open({ id: 'needs', status: 'needs_photos' });
  assert.deepEqual(events, ['screen:photos']);
  assert.equal(submission.id, 'needs');
});

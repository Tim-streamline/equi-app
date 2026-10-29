import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

// Execute the screen's upload handler so these tests cover wiring as well as name selection.
const source = readFileSync(new URL('../app/(tabs)/scanner/index.tsx', import.meta.url), 'utf8');
const handler = ts.transpileModule(source.slice(source.indexOf('  async function persistPhoto('), source.indexOf('  async function continueAnalysis(')), {
  compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;

function harness(name = '', previousSuggestion = null) {
  let resolve;
  const context = vm.createContext({
    userId: 'owner', currentUser: { current: 'owner' }, mounted: { current: true },
    submission: { current: { id: 'scan', suggested_name: previousSuggestion } }, uploadedAssets: { current: {} },
    name, selected: null, uploadingKey: null, setName: update => { context.name = update(context.name); },
    setUploadingKey: key => { context.uploadingKey = key; }, setRecognition: () => {}, setPhotoStep: () => {}, setError: () => {},
    uploadFeedPhoto: () => new Promise(done => { resolve = done; }),
  });
  vm.runInContext(handler, context);
  return { context, start: (slot = 'front') => context.persistPhoto(slot, { uri: 'photo.jpg' }),
    finish: (suggested_name, fields = {}) => resolve({ id: 'scan', status: 'needs_photos', suggested_name, ...fields }) };
}

test('the third photo fills the product name; the first two do not', async () => {
  for (const slot of ['composition', 'analysis', 'front']) {
    const h = harness();
    const upload = h.start(slot);
    h.finish('Vitalbix Nutrimash');
    await upload;
    assert.equal(h.context.name, slot === 'front' ? 'Vitalbix Nutrimash' : '');
  }
});

test('a replacement front photo updates its previous automatic name', async () => {
  const h = harness('Old name', 'Old name');
  const upload = h.start();
  h.finish('New name');
  await upload;
  assert.equal(h.context.name, 'New name');
});

test('catalog selections keep their name even if Luna returns a different suggestion', async () => {
  for (const name of ['Known product', '']) {
    const h = harness(name, name);
    h.context.selected = { id: 'catalog-product', name: 'Known product' };
    const upload = h.start();
    h.finish('Luna name');
    await upload;
    assert.equal(h.context.name, name);
  }
});

test('resumed scans linked to a known product never apply a Luna name', async () => {
  const h = harness('Known product', 'Known product');
  const upload = h.start();
  h.finish('Luna name', { reference_product_id: 'catalog-product' });
  await upload;
  assert.equal(h.context.name, 'Known product');
});

test('typed names, including edits while Luna is running, are preserved', async () => {
  const h = harness('Old name', 'Old name');
  const upload = h.start();
  h.context.name = 'My correction';
  h.finish('New name');
  await upload;
  assert.equal(h.context.name, 'My correction');
});

test('failed or unreadable replacements clear automatic names but preserve typed names', async () => {
  for (const name of ['Old name', 'My correction', '']) {
    const h = harness(name, 'Old name');
    const upload = h.start();
    h.finish(null);
    await upload;
    assert.equal(h.context.name, name === 'My correction' ? name : '');
  }
});

test('an old upload cannot set the name or upload state of a new scan', async () => {
  const h = harness();
  const upload = h.start();
  h.context.submission.current = { id: 'new-scan' };
  h.context.name = 'New scan name';
  h.context.uploadingKey = 'analysis';
  h.finish('Old scan name');
  await upload;
  assert.equal(h.context.name, 'New scan name');
  assert.equal(h.context.submission.current.id, 'new-scan');
  assert.equal(h.context.uploadingKey, 'analysis');
});

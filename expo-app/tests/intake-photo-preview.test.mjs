import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
function load(path, cache = {}) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
  }).outputText;
  new Function('exports', 'require', code)(exports, () => ({ previewCache: cache }));
  return exports;
}
const tick = () => new Promise(resolve => setImmediate(resolve));

test('preview dimensions cap both orientations without upscaling', () => {
  const { previewDimensions } = load('../lib/intake/attachments/preview.web.ts');
  assert.deepEqual(previewDimensions(6000, 4000), { width: 960, height: 640 });
  assert.deepEqual(previewDimensions(4000, 6000), { width: 640, height: 960 });
  assert.deepEqual(previewDimensions(80, 60), { width: 80, height: 60 });
});

test('preview queue loads one original at a time, closes bitmaps and skips cancelled work', async t => {
  const { queuePhotoPreview } = load('../lib/intake/attachments/preview.web.ts');
  let finishFirst, decoded = 0, closed = 0, active = 0, maximum = 0;
  const canvases = [];
  const originalDocument = globalThis.document, originalBitmap = globalThis.createImageBitmap;
  t.after(() => { globalThis.document = originalDocument; globalThis.createImageBitmap = originalBitmap; });
  globalThis.createImageBitmap = async () => {
    decoded++; active++; maximum = Math.max(maximum, active);
    if (decoded === 1) await new Promise(resolve => { finishFirst = resolve; });
    return { width: 6000, height: 4000, close() { active--; closed++; } };
  };
  globalThis.document = { createElement: () => {
    const canvas = { width: 0, height: 0, getContext: () => ({ fillRect() {}, drawImage() {} }),
      toBlob(callback) { assert.equal(active, 0); callback(new Blob(['thumbnail'], { type: 'image/jpeg' })); } };
    canvases.push(canvas); return canvas;
  } };
  const first = queuePhotoPreview(async () => new Blob(['one']), new AbortController().signal);
  await tick();
  const controller = new AbortController();
  const cancelled = queuePhotoPreview(async () => assert.fail('cancelled job must not read bytes'), controller.signal);
  const cancellation = assert.rejects(cancelled, { name: 'AbortError' });
  controller.abort();
  let secondLoaded = false;
  const second = queuePhotoPreview(async () => { secondLoaded = true; return new Blob(['two']); }, new AbortController().signal);
  await tick(); assert.equal(secondLoaded, false);
  finishFirst(); await Promise.all([first, second, cancellation]);
  assert.equal(maximum, 1); assert.equal(decoded, 2); assert.equal(closed, 2);
  for (const canvas of canvases) assert.deepEqual([canvas.width, canvas.height], [0, 0]);
  await assert.rejects(queuePhotoPreview(async () => { throw new Error('offline'); }, new AbortController().signal), /offline/);
  assert.equal(await (await queuePhotoPreview(async () => new Blob(['three']), new AbortController().signal)).text(), 'thumbnail');
});

test('attachment notifications identify the changed owner and attachment and unsubscribe cleanly', () => {
  const { attachmentsChanged, onAttachmentsChanged } = load('../lib/intake/attachments/events.ts');
  const changes = [];
  const unsubscribe = onAttachmentsChanged(change => changes.push(change));
  attachmentsChanged({ userId: 'one', id: 'photo-a' });
  unsubscribe(); attachmentsChanged({ userId: 'two', id: 'photo-b' });
  assert.deepEqual(changes, [{ userId: 'one', id: 'photo-a' }]);
});

test('older-browser preview fallback clears its source, object URL and canvas', async t => {
  const { queuePhotoPreview } = load('../lib/intake/attachments/preview.web.ts');
  const previous = { document: globalThis.document, Image: globalThis.Image, createImageBitmap: globalThis.createImageBitmap };
  t.after(() => Object.assign(globalThis, previous));
  globalThis.createImageBitmap = undefined;
  const images = [], revoked = [];
  t.mock.method(URL, 'createObjectURL', () => 'blob:fallback');
  t.mock.method(URL, 'revokeObjectURL', url => revoked.push(url));
  globalThis.Image = class {
    naturalWidth = 4000; naturalHeight = 6000;
    constructor() { images.push(this); }
    set src(value) { this.current = value; if (value) queueMicrotask(() => this.onload?.()); }
  };
  const canvas = { width: 0, height: 0, getContext: () => ({ fillRect() {}, drawImage() {
    assert.equal(canvas.width, 640); assert.equal(canvas.height, 960);
  } }), toBlob: callback => callback(new Blob(['preview'])) };
  globalThis.document = { createElement: () => canvas };
  await queuePhotoPreview(async () => new Blob(['original']), new AbortController().signal);
  assert.deepEqual(revoked, ['blob:fallback']);
  assert.equal(images[0].current, '');
  assert.equal(canvas.width, 0); assert.equal(canvas.height, 0);
});

test('cached preview avoids reading originals and scopes entries by owner', async () => {
  const calls = [];
  const thumbnail = new Blob(['small'], { type: 'image/jpeg' });
  const cache = { get: async key => { calls.push(key); return key.userId === 'one' ? thumbnail : undefined; }, put: async (key, blob) => calls.push({ key, blob }) };
  const { queuePhotoPreview } = load('../lib/intake/attachments/preview.web.ts', cache);
  const signal = new AbortController().signal;
  assert.equal(await queuePhotoPreview(async () => assert.fail('must not read original'), signal, { userId: 'one', id: 'a' }), thumbnail);
  assert.equal(await queuePhotoPreview(async () => ({ preview: thumbnail }), signal, { userId: 'two', id: 'a' }), thumbnail);
  assert.deepEqual(calls, [{ userId: 'one', id: 'a' }, { userId: 'two', id: 'a' }, { key: { userId: 'two', id: 'a' }, blob: thumbnail }]);
});

test('server thumbnails bypass browser decoding even when the local cache is unavailable', async () => {
  const { queuePhotoPreview } = load('../lib/intake/attachments/preview.web.ts', { get: async () => { throw new Error('private mode'); }, put: async () => { throw new Error('quota'); } });
  const thumbnail = new Blob(['small'], { type: 'image/jpeg' });
  assert.equal(await queuePhotoPreview(async () => ({ preview: thumbnail }), new AbortController().signal, { userId: 'one', id: 'a' }), thumbnail);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readPhotoDimensions } from '../lib/intake/attachments/image-dimensions.web.ts';
import { validatePhotoDimensions } from '../lib/intake/attachments/photo-dimensions.ts';

function jpeg(width, height) {
  // EXIF/APP metadata precedes the progressive SOF marker.
  return new Blob([Uint8Array.from([255,216,255,225,0,6,1,2,3,4,255,194,0,8,8,
    height >> 8, height & 255, width >> 8, width & 255,1,255,217])]);
}
function png(width, height) {
  const bytes = new Uint8Array(24), view = new DataView(bytes.buffer);
  bytes.set([137,80,78,71,13,10,26,10,0,0,0,13,73,72,68,82]);
  view.setUint32(16, width); view.setUint32(20, height);
  return new Blob([bytes]);
}
function webp(type, width, height) {
  const frame = new Uint8Array(type === 'VP8L' ? 5 : 10), view = new DataView(frame.buffer);
  if (type === 'VP8X') {
    view.setUint32(4, width - 1, true);
    frame[7] = (height - 1) & 255; frame[8] = ((height - 1) >> 8) & 255; frame[9] = ((height - 1) >> 16) & 255;
  } else if (type === 'VP8L') {
    frame[0] = 0x2f; view.setUint32(1, (width - 1) | ((height - 1) << 14), true);
  } else {
    frame.set([0x9d,1,0x2a],3); view.setUint16(6,width,true); view.setUint16(8,height,true);
  }
  const header = new Uint8Array(20), h = new DataView(header.buffer);
  header.set(new TextEncoder().encode('RIFF'),0); header.set(new TextEncoder().encode('WEBP'+type),8);
  h.setUint32(4,12+frame.length+(frame.length%2),true); h.setUint32(16,frame.length,true);
  return new Blob([header,frame,new Uint8Array(frame.length%2)]);
}

for (const [format, make] of [['JPEG', jpeg], ['PNG', png], ...['VP8 ', 'VP8L', 'VP8X'].map(type => [type, (w,h) => webp(type,w,h)])]) {
  test(`${format} dimensions are read from headers and enforce both orientations`, async () => {
    for (const [width,height,allowed] of [[7680,4320,true],[4320,7680,true],[6000,4000,true],
      [7681,4320,false],[4320,7681,false],[7680,4321,false],[4321,7680,false],[5000,5000,false]]) {
      const dimensions = await readPhotoDimensions(make(width,height));
      assert.deepEqual(dimensions,{width,height});
      if (allowed) assert.doesNotThrow(()=>validatePhotoDimensions(dimensions));
      else assert.throws(()=>validatePhotoDimensions(dimensions),/7680 × 4320/);
    }
  });
}

test('header inspection does not read the original pixel payload', async () => {
  const file = new Blob([jpeg(7680,4320), new Uint8Array(12 * 1024 * 1024)]);
  const slice = file.slice.bind(file); let bytesRead = 0;
  file.slice = (start,end) => { bytesRead += end-start; return slice(start,end); };
  assert.deepEqual(await readPhotoDimensions(file),{width:7680,height:4320});
  assert.ok(bytesRead < 100);
});

test('truncated or malformed headers fail without decoding', async () => {
  for (const file of [new Blob(), new Blob(['invalid']), new Blob([Uint8Array.from([255,216,255,225,0,0])]),
    jpeg(10,10).slice(0,12), webp('VP8X',10,10).slice(0,24)]) {
    await assert.rejects(readPhotoDimensions(file),/foto kon niet worden gelezen/);
  }
});

// Exercise the actual web picker: header reads must preserve the original File
// and release any URLs allocated before a later unreadable file is encountered.
import { readFileSync } from 'node:fs';
import ts from 'typescript';
function pickerFixture(t, files) {
  const events = {}, urls = [], revoked = [];
  let removed = false;
  const input = { files, style: {}, addEventListener: (name, callback) => { events[name] = callback; },
    click() {}, remove() { removed = true; } };
  const original = globalThis.document;
  globalThis.document = { createElement: () => input, body: { appendChild() {} } };
  t.after(() => { globalThis.document = original; });
  t.mock.method(URL, 'createObjectURL', file => { urls.push(file); return `blob:photo-${urls.length}`; });
  t.mock.method(URL, 'revokeObjectURL', url => revoked.push(url));
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL('../lib/intake/attachments/image-picker.web.ts', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  new Function('require', 'exports', code)(() => ({ readPhotoDimensions }), exports);
  return { ...exports, events, urls, revoked, removed: () => removed };
}

test('web picker returns accurate dimensions and retains original bytes', async t => {
  const file = new File([jpeg(4320,7680)], 'portrait.jpg', { type: 'image/jpeg' });
  const app = pickerFixture(t, [file]);
  const pending = app.launchImageLibraryAsync({});
  await app.events.change();
  const result = await pending;
  assert.equal(result.assets[0].file, file);
  assert.deepEqual([result.assets[0].width,result.assets[0].height],[4320,7680]);
  assert.equal(app.removed(),true);
  app.releasePickedImages(result.assets);
  assert.deepEqual(app.revoked,['blob:photo-1']);
});

test('web picker cleans up partial selections after a header-read failure', async t => {
  const app = pickerFixture(t, [new File([jpeg(100,100)],'good.jpg'),new File(['bad'],'broken.jpg')]);
  const failure = assert.rejects(app.launchImageLibraryAsync({ allowsMultipleSelection: true }), /foto kon niet worden gelezen/);
  await app.events.change(); await failure;
  assert.equal(app.removed(),true);
  assert.deepEqual(app.revoked,['blob:photo-1']);
});

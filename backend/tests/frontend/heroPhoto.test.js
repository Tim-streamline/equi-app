import assert from 'node:assert/strict';
import test from 'node:test';
import { prepareHeroPhoto } from '../../resources/js/lib/hero-photo.js';

test('small photos keep their original bytes and transparency', async () => {
    const file = new File(['small PNG'], 'photo.png', { type: 'image/png' });
    assert.equal(await prepareHeroPhoto(file), file);
    assert.equal(await prepareHeroPhoto(null), null);
});

test('large photos are resized and encoded for upload with the correct filename', async t => {
    let closed = false;
    let drawn = false;
    const image = { width: 6000, height: 4000, close() { closed = true; } };
    const canvas = { width: 0, height: 0, getContext: () => ({ fillRect() {}, drawImage(source, x, y, width, height) {
        assert.equal(source, image); assert.equal(width, 1920); assert.equal(height, 1280); drawn = true;
    } }), toBlob(callback, type, quality) {
        assert.equal(type, 'image/jpeg'); assert.equal(quality, 0.85);
        callback(new Blob(['compressed'], { type }));
    } };
    const originalBitmap = globalThis.createImageBitmap;
    const originalDocument = globalThis.document;
    globalThis.createImageBitmap = async () => image;
    globalThis.document = { createElement: () => canvas };
    t.after(() => {
        if (originalBitmap === undefined) delete globalThis.createImageBitmap;
        else globalThis.createImageBitmap = originalBitmap;
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    });
    const result = await prepareHeroPhoto(new File([new Uint8Array(12 * 1024 * 1024)], 'horse.png', { type: 'image/png' }));
    assert.equal(result.name, 'horse.jpg');
    assert.equal(result.type, 'image/jpeg');
    assert.ok(result.size < 10 * 1024 * 1024);
    assert.ok(drawn); assert.ok(closed);
});

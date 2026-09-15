import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import test from 'node:test';

// Exercise the upload callbacks with a persistent SPA document and rotating cookies.
async function uploader(name) {
    const source = await readFile(new URL(`../../resources/js/lib/components/${name}.svelte`, import.meta.url), 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');
    const document = { cookie: 'XSRF-TOKEN=after%2Flogin%3D', querySelector: () => ({ content: 'before-login' }) };
    let options;
    const requests = [];
    const context = vm.createContext({
        document, Set, Number, FormData, URL,
        Image: null, Film: null, Music: null,
        $props: () => ({ libraryItemId: 'item-123' }),
        $state: (value) => value, $bindable: (value) => value, $derived: (value) => value,
        onMount: (callback) => callback(), onDestroy: () => {},
        create: (_, config) => { options = config; return { removeFile() {} }; },
        confirm: () => true,
        fetch: async (url, init) => { requests.push({ url, ...init }); return { ok: true, json: async () => ({ asset: { url: '/image.png' } }) }; },
    });
    const helper = await readFile(new URL('../../resources/js/lib/csrf.js', import.meta.url), 'utf8').catch(() => '');
    vm.runInContext(helper.replace(/export /g, '') + '\n' + script, context);
    return { context, document, requests, options };
}

function resolveHeaders(action, argument) {
    return typeof action.headers === 'function' ? action.headers(argument) : action.headers;
}

test('chunk creation and every PATCH use the current cookie after SPA login and token rotation', async () => {
    const { options, document } = await uploader('MediaUploader');
    const file = { size: 1234, name: 'lesson.mp4' };
    const start = resolveHeaders(options.server.process, file);
    assert.equal(start['X-XSRF-TOKEN'], 'after/login=');
    assert.equal(start['X-CSRF-TOKEN'], undefined);
    assert.equal(start['X-Library-Item-Id'], 'item-123');
    assert.equal(start['Upload-Length'], file.size);

    document.cookie = 'other=value; XSRF-TOKEN=rotated%2Btoken%3D';
    const patch = resolveHeaders(options.server.patch, { file, offset: 24 });
    assert.equal(patch['X-XSRF-TOKEN'], 'rotated+token=');
    assert.equal(patch['Content-Type'], 'application/offset+octet-stream');
    assert.equal(patch['Upload-Offset'], 24);
    assert.equal(patch['Upload-Length'], file.size);
    assert.equal(patch['Upload-Name'], file.name);
    assert.equal(resolveHeaders(options.server.process, 'resume-id')['X-XSRF-TOKEN'], 'rotated+token=');
});

test('cancel and media deletion use the current cookie', async () => {
    const { context, requests, document } = await uploader('MediaUploader');
    document.cookie = 'XSRF-TOKEN=new%3D';
    await vm.runInContext('revertUpload("transfer-id", () => {}, () => {})', context);
    await vm.runInContext('remove({ id: "asset-id", original_name: "test.png" })', context);
    assert.equal(requests.length, 2);
    for (const request of requests) {
        assert.equal(request.method, 'DELETE');
        assert.equal(request.headers['X-XSRF-TOKEN'], 'new=');
        assert.equal(request.headers['X-CSRF-TOKEN'], undefined);
    }
});

test('thumbnail uploads use the current cookie instead of stale page metadata', async () => {
    const { context, requests } = await uploader('ThumbnailUploader');
    context.file = new File(['image'], 'image.png', { type: 'image/png' });
    await vm.runInContext('input = { value: "" }; upload({ currentTarget: { files: [file] } })', context);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].headers['X-XSRF-TOKEN'], 'after/login=');
    assert.equal(requests[0].headers['X-CSRF-TOKEN'], undefined);
    assert.equal(requests[0].body.get('purpose'), 'thumbnail');
});

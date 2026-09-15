import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url), 'utf8');
const saveFunction = source.slice(source.indexOf('    function save('), source.indexOf('    function submit('));

function editor({ isNew = true, published = false, processing = false } = {}) {
    const requests = [];
    const form = {
        published, processing, title: 'Actuele wijzigingen',
        transform(callback) { this.payload = callback({ title: this.title, published: this.published }); return this; },
        post(url, options) { requests.push({ method: 'post', url, options, payload: this.payload }); },
        put(url, options) { requests.push({ method: 'put', url, options, payload: this.payload }); },
    };
    const context = { $form: form, isNew, protocol: isNew ? null : { id: 'stored-id' }, saveError: '', pendingSave: false, revealSaveErrors() {} };
    runInNewContext(`${saveFunction}; this.save = save;`, context);
    return { ...context, requests };
}

test('successful save reloads database identity; validation failure preserves user input', () => {
    const page = editor();
    page.save(false);
    assert.equal(page.requests[0].options?.preserveState, 'errors');
});

test('save of a published protocol submits current changes as a draft', () => {
    const page = editor({ isNew: false, published: true });
    page.save();
    assert.equal(page.requests[0].method, 'put');
    assert.equal(page.requests[0].payload?.published, false);
    assert.equal(page.requests[0].payload?.title, 'Actuele wijzigingen');
});

test('publish sends current content without claiming publication before success', () => {
    const page = editor();
    page.save(true);
    assert.equal(page.$form.published, false);
    assert.equal(page.requests[0].payload?.published, true);
    assert.equal(page.requests[0].payload?.title, 'Actuele wijzigingen');
});

test('repeated clicks during a save cannot send a second request', () => {
    const page = editor({ processing: true });
    page.save(true);
    assert.equal(page.requests.length, 0);
});

test('analysis editor and preview no longer ask for protocol focus points', () => {
    assert.doesNotMatch(source, /Focus van het protocol|addFocusPoint|analysis\.focus_points/);
    assert.match(source, /aria-label="Persoonlijke analyse"/);
    assert.match(source, /Waar letten we op\?/);
});

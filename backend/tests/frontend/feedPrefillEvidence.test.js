import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

// Exercise the page's actual async handler, including its stale-response guard.
const source = readFileSync(new URL('../../resources/js/Pages/FeedScanner/Products.svelte', import.meta.url), 'utf8');
const handler = source.slice(source.indexOf('    async function prefill()'), source.indexOf('    function save(e)'));
const plain = value => JSON.parse(JSON.stringify(value));
function harness() {
    const responses = [];
    const context = vm.createContext({
        FormData, aiPhotos: [new Blob(['label'])], aiText: '', aiError: '', aiNotes: null, aiAnalyses: [], aiBusy: false, formGeneration: 1,
        $form: { ingredients: [], nutrients: [], analysis_attachment_ids: [], source: '' }, csrfHeaders: () => ({}),
        fetch: async () => responses.shift(),
    });
    vm.runInContext(handler, context);
    return { context, run: (result, ok = true) => {
        responses.push({ ok, json: async () => result });
        return vm.runInContext('prefill()', context);
    }, pending: promise => { responses.push(promise); return vm.runInContext('prefill()', context); } };
}
const reading = (id, data = {}) => ({
    analysis_attachment_ids: [id], analyses: [{ id, name: `${id}.jpg`, ocr: { text: `OCR ${id}` }, luna: { status: 'completed' } }],
    data: { ingredients: [], nutrients: [], ...data }, notes: [],
});

test('successive composition and nutrition prefills retain both sets of evidence on save', async () => {
    const { context, run } = harness();
    await run(reading('composition', { ingredients: [{ label: 'Luzerne', amount: '80%' }] }));
    await run(reading('nutrition', { nutrients: [{ code: 'sugar', value: '4.2', basis: 'product' }] }));
    assert.equal(context.$form.ingredients[0].label, 'Luzerne');
    assert.equal(context.$form.nutrients[0].value, '4.2');
    assert.deepEqual(plain(context.$form.analysis_attachment_ids), ['composition', 'nutrition']);
    assert.deepEqual(plain(context.aiAnalyses.map(row => row.id)), ['composition', 'nutrition']);
    // Reusing a response cannot duplicate an already attached comparison.
    await run(reading('nutrition'));
    assert.equal(context.aiAnalyses.length, 2);
    assert.equal(context.$form.analysis_attachment_ids.length, 2);
});

test('failed and text-only prefills preserve earlier fields and photo comparisons', async () => {
    const { context, run } = harness();
    await run(reading('composition', { ingredients: [{ label: 'Luzerne' }] }));
    await run({ ...reading('failed'), message: 'Luna failed' }, false);
    assert.equal(context.aiError, 'Luna failed');
    context.aiPhotos = [];
    context.aiText = 'Product label';
    await run({ data: { name: 'Feed', ingredients: [], nutrients: [] }, analyses: [], analysis_attachment_ids: [], notes: [] });
    assert.equal(context.$form.ingredients[0].label, 'Luzerne');
    assert.deepEqual(plain(context.$form.analysis_attachment_ids), ['composition', 'failed']);
    assert.equal(context.aiAnalyses.length, 2);
});

test('conflicting nutrient readings clear a prior value only for the affected code and basis', async () => {
    const { context, run } = harness();
    context.$form.nutrients = [{ code: 'sugar', value: '4.2', basis: 'product' }, { code: 'sugar', value: '5', basis: 'dry_matter' }];
    await run({ ...reading('conflict'), nutrient_conflicts: [{ code: 'sugar', basis: 'product' }] });
    assert.deepEqual(plain(context.$form.nutrients), [{ code: 'sugar', value: '5', basis: 'dry_matter' }]);
});

test('a response arriving after switching products cannot attach its evidence to the new form', async () => {
    const { context, pending } = harness();
    let resolve;
    const request = pending(new Promise(done => resolve = done));
    context.formGeneration++;
    resolve({ ok: true, json: async () => reading('old-product') });
    await request;
    assert.deepEqual(plain(context.$form.analysis_attachment_ids), []);
    assert.equal(context.aiAnalyses.length, 0);
});

test('the cumulative photo limit prevents an unsaveable form before uploading another batch', async () => {
    const { context, run } = harness();
    context.$form.analysis_attachment_ids = Array.from({ length: 20 }, (_, index) => `photo-${index}`);
    await run(reading('extra'));
    assert.match(context.aiError, /maximaal 20/);
    assert.equal(context.$form.analysis_attachment_ids.length, 20);
});

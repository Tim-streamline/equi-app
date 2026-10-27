import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/FeedScanner/Products.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');

function setup(fetch = () => { throw new Error('Unexpected request'); }) {
    const requests = [];
    let defaults;
    const form = {
        defaults(value) { defaults = structuredClone(value); },
        reset() { Object.assign(this, structuredClone(defaults)); },
        clearErrors() {},
        put(url) { requests.push({ url, data: structuredClone(defaults) }); },
    };
    const uploadForm = { reset() {}, clearErrors() {}, post(url) { requests.push({ url }); } };
    const props = { products: { data: [] }, filters: {}, categories: { complementary: 'Aanvullend' }, ingredientOptions: [] };
    const run = new Function('$props', '$state', '$derived', 'useForm', '$form', '$uploadForm', 'router', 'prompt', 'fetch', 'csrfHeaders', `${script}
        return { edit, save, reviewProduct, beginUpload, upload, prefill, setProductOpen,
            setLabel: text => aiText = text, aiState: () => ({ aiBusy, aiError, aiNotes }),
            modalState: () => ({ open, historyOpen, uploadOpen }) };`);
    const actions = run(() => props, value => value, value => value, () => ({}), form, uploadForm,
        { post: (url, data) => requests.push({ url, data }) }, () => 'Meer informatie over dit etiket', fetch, () => ({}));
    return { actions, form, requests };
}

const older = { id: 'alice-revision', number: 2, is_current: false, data: { name: 'Alice', brand: 'Merk A', category: 'complementary', ingredients: [], nutrients: [] } };
const newer = { id: 'bob-revision', number: 3, is_current: true, data: { name: 'Bob', brand: 'Merk B', category: 'complementary', ingredients: [], nutrients: [] } };
const product = { id: 'feed', versions: [newer, older] };

test('correction forms send the selected source and concurrency token and default to the current revision', () => {
    const { actions, form, requests } = setup();
    actions.edit(product, older);
    assert.equal(form.name, 'Alice');
    assert.equal(form.brand, 'Merk A');
    actions.save({ preventDefault() {} });
    assert.equal(requests[0].url, '/admin/feed-scanner/products/feed');
    assert.equal(requests[0].data.source_version_id, older.id);
    assert.equal(requests[0].data.expected_number, older.number);
    assert.deepEqual(actions.modalState(), { open: true, historyOpen: false, uploadOpen: false });
    actions.edit(product);
    assert.equal(form.source_version_id, newer.id);
    assert.equal(form.name, 'Bob');
    actions.edit();
    assert.equal(form.source_version_id, null);
});

test('review and attachment actions target the chosen revision', () => {
    const { actions, requests } = setup();
    for (const decision of ['approve', 'needs_info', 'reject']) {
        actions.reviewProduct(product, decision, older);
        assert.equal(requests.at(-1).data.version_id, older.id);
        assert.equal(requests.at(-1).data.decision, decision);
    }
    actions.beginUpload(older);
    actions.upload({ preventDefault() {} });
    assert.equal(requests.at(-1).url, `/admin/feed-scanner/versions/${older.id}/attachments`);
    assert.deepEqual(actions.modalState(), { open: false, historyOpen: false, uploadOpen: true });
});


test('adding a product starts a blank draft without a recipe relationship', () => {
    const { actions, form } = setup();
    actions.edit();
    assert.equal(form.related_product_id, null);
    assert.equal(form.source_version_id, null);
    assert.equal(form.expected_number, null);
    assert.equal(form.brand, '');
    assert.equal(form.barcode, '');
    assert.deepEqual(form.ingredients, []);
    assert.deepEqual(form.nutrients, []);
    assert.equal(form.composition_text, '');
});

const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const extraction = name => ({ ok: true, json: async () => ({
    data: { name, ingredients: [{ label: `${name} ingredient` }], nutrients: [{ code: 'sugar', value: '3' }] }, notes: [`Check ${name}`],
}) });

test('an extraction from a closed product cannot overwrite the next product or finish its loading state', async () => {
    const first = deferred(), second = deferred();
    const replies = [first, second];
    const { actions, form } = setup(() => replies.shift().promise);
    actions.edit(product, older);
    actions.setLabel('Alice label');
    const pendingFirst = actions.prefill();
    actions.setProductOpen(false);
    actions.edit({ id: 'other-product', versions: [newer] });
    actions.setLabel('Bob label');
    const pendingSecond = actions.prefill();
    first.resolve(extraction('Alice'));
    await pendingFirst;
    assert.equal(form.name, 'Bob');
    assert.equal(form.source_version_id, newer.id);
    assert.deepEqual(form.ingredients, []);
    assert.deepEqual(form.nutrients, []);
    assert.deepEqual(actions.aiState(), { aiBusy: true, aiError: '', aiNotes: null });
    second.resolve(extraction('Bob extracted'));
    await pendingSecond;
    assert.equal(form.name, 'Bob extracted');
    assert.deepEqual(form.ingredients, [{ label: 'Bob extracted ingredient' }]);
    assert.deepEqual(actions.aiState(), { aiBusy: false, aiError: '', aiNotes: ['Check Bob extracted'] });
});

test('closing the product discards its extraction even without opening another form', async () => {
    const response = deferred();
    const { actions, form } = setup(() => response.promise);
    actions.edit(product, older);
    actions.setLabel('Alice label');
    const pending = actions.prefill();
    actions.setProductOpen(false);
    response.resolve(extraction('Changed'));
    await pending;
    assert.equal(form.name, 'Alice');
    assert.deepEqual(actions.aiState(), { aiBusy: false, aiError: '', aiNotes: null });
});

for (const failure of ['network', 'server']) test(`a stale ${failure} error does not affect another extraction`, async () => {
    const first = deferred(), second = deferred();
    const replies = [first, second];
    const { actions } = setup(() => replies.shift().promise);
    actions.edit(product, older);
    actions.setLabel('Alice label');
    const pendingFirst = actions.prefill();
    actions.edit(product, newer);
    actions.setLabel('Bob label');
    const pendingSecond = actions.prefill();
    if (failure === 'network') first.reject(new Error('Offline'));
    else first.resolve({ ok: false, json: async () => ({ message: 'Old error' }) });
    await pendingFirst;
    assert.deepEqual(actions.aiState(), { aiBusy: true, aiError: '', aiNotes: null });
    second.resolve(extraction('Bob'));
    await pendingSecond;
});

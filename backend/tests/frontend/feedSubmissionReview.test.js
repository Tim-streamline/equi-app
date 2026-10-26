import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/FeedScanner/Products.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');

function setup() {
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
    const run = new Function('$props', '$state', '$derived', 'useForm', '$form', '$uploadForm', 'router', 'prompt', `${script}
        return { edit, newRecipe, save, reviewProduct, beginUpload, upload, modalState: () => ({ open, historyOpen, uploadOpen }) };`);
    const actions = run(() => props, value => value, value => value, () => ({}), form, uploadForm,
        { post: (url, data) => requests.push({ url, data }) }, () => 'Meer informatie over dit etiket');
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


test('another recipe starts a separate product without copying composition or evidence', () => {
    const { actions, form } = setup();
    actions.newRecipe({ id: 'original', brand: 'Merk', name: 'Voer', barcode: '123', versions: [newer] });
    assert.equal(form.related_product_id, 'original');
    assert.equal(form.source_version_id, null);
    assert.equal(form.expected_number, null);
    assert.equal(form.brand, 'Merk');
    assert.equal(form.barcode, '123');
    assert.deepEqual(form.ingredients, []);
    assert.deepEqual(form.nutrients, []);
    assert.equal(form.composition_text, '');
});

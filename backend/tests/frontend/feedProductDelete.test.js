import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/Pages/FeedScanner/Products.svelte', import.meta.url), 'utf8');
const remove = source.match(/function remove\(record\) \{[\s\S]*?\n    \}/)[0];

test('product deletion only sends the confirmed request after accepting the named warning', () => {
    const calls = [], messages = [];
    let accepted = false;
    const context = vm.createContext({
        record: { id: 'duplicate', brand: 'Pavo', name: 'FibreNuggets' },
        confirm: message => { messages.push(message); return accepted; },
        router: { delete: (...args) => calls.push(args) },
    });
    vm.runInContext(remove, context);
    vm.runInContext('remove(record)', context);
    assert.equal(calls.length, 0);
    assert.match(messages[0], /Weet je zeker dat je dit wil verwijderen\?/);
    assert.match(messages[0], /Pavo · FibreNuggets/);
    assert.match(messages[0], /scans, inzendingen, beoordelingen en etiketfoto's/);
    accepted = true;
    vm.runInContext('remove(record)', context);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/admin/feed-scanner/products/duplicate');
    assert.equal(calls[0][1].data.confirmed, true);
});

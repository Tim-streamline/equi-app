import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const script = (await readFile(new URL('../../resources/js/Pages/Library/Edit.svelte', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');
function formFor(item) {
    const context = vm.createContext({ $props: () => ({ item, categories: [], therapists: [] }), $state: v => v, $derived: v => v, useForm: v => { context.$form = v; return v; } });
    vm.runInContext(script, context);
    return context.$form;
}
test('new Library items default to Plus only unchecked', () => assert.equal(formFor(null).is_plus, false));
test('editing Library items preserves their saved Plus access setting', () => {
    assert.equal(formFor({ is_plus: true }).is_plus, true);
    assert.equal(formFor({ is_plus: false }).is_plus, false);
});

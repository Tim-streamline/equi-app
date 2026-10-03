import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/Pages/Library/Edit.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');
function editor() {
    const calls = [];
    let accepted = false;
    const context = vm.createContext({
        $props: () => ({ item: { id: 'lesson', published_at: '2026-01-01', title: 'Original' }, categories: [], therapists: [] }),
        $state: v => v, $derived: v => v, confirm: () => accepted,
        useForm: data => {
            const form = { ...data, processing: false, errors: {}, post: (...args) => calls.push(args) };
            context[context.$form ? '$publication' : '$form'] = form;
            return form;
        },
    });
    vm.runInContext(script, context);
    return { context, calls, accept: () => { accepted = true; }, unpublish: () => vm.runInContext('unpublish()', context) };
}

test('draft action requires confirmation and changes only publication while retaining unsaved edits', () => {
    const page = editor();
    page.context.$form.title = 'Unsaved title';
    page.unpublish();
    assert.equal(page.calls.length, 0);
    assert.equal(page.context.$form.published_at, '2026-01-01');
    page.accept(); page.unpublish();
    assert.equal(page.calls[0][0], '/admin/library/lesson/unpublish');
    assert.equal(page.context.$form.published_at, '2026-01-01', 'Failed requests must leave the publication field unchanged');
    page.calls[0][1].onSuccess();
    assert.equal(page.context.$form.published_at, '');
    assert.equal(page.context.$form.title, 'Unsaved title');
    page.context.$publication.processing = true;
    page.unpublish();
    assert.equal(page.calls.length, 1);
});

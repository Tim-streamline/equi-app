import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/lib/components/EvaluationQuestionEditor.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');

function editor() {
    const calls = [];
    let accepted = false;
    const context = vm.createContext({
        $props: () => ({ question: { id: 'q1', label: 'Hoe gaat het?', order: 0, active: true } }),
        confirm: () => accepted,
        useForm: data => {
            const form = { ...data, processing: false, errors: {}, delete: (...args) => calls.push(args) };
            context.$form = form;
            return form;
        },
    });
    vm.runInContext(script, context);
    return { context, calls, accept: () => { accepted = true; }, remove: () => vm.runInContext('remove()', context) };
}

test('deleting goes through the question form, so its errors show there, and success syncs the form', () => {
    const page = editor();
    page.remove();
    assert.equal(page.calls.length, 0, 'nothing happens without confirmation');
    page.accept();
    page.remove();
    assert.equal(page.calls[0][0], '/admin/evaluation-questions/q1');
    assert.equal(page.calls[0][1].preserveScroll, true);
    // Refused (the last active question): the form keeps it active and renders the server error below it.
    assert.equal(page.context.$form.active, true);
    assert.match(source, /\{#if \$form\.errors\.active\}<p class="text-sm text-destructive">\{\$form\.errors\.active\}<\/p>\{\/if\}/);
    assert.doesNotMatch(source, /router\.delete/);
    page.calls[0][1].onSuccess();
    assert.equal(page.context.$form.active, false, 'a later save must not reactivate the deleted question');
});

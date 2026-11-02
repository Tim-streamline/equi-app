import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/IntakeQuestionnaire/Index.svelte', import.meta.url), 'utf8');
const handlers = source.slice(source.indexOf('    function renameOption('), source.indexOf('    function emptyField()'));
function harness() {
    const context = vm.createContext({ $fieldForm: {
        option_keys: { A: 'key-a', Geen: 'key-none' }, options_json: JSON.stringify(['A', 'Geen']), option_effects: { Geen: { exclusive_selection: true } },
    } });
    vm.runInContext('function parsedOptions() { return JSON.parse($fieldForm.options_json); }' + handlers, context);
    return { context, run: code => vm.runInContext(code, context), effects: () => JSON.parse(JSON.stringify(context.$fieldForm.option_effects)) };
}

test('renaming an answer keeps its exclusive selection setting', () => {
    const { context, run, effects } = harness();
    run("renameOption(1, 'Niets van toepassing')");
    assert.deepEqual(effects(), { 'Niets van toepassing': { exclusive_selection: true } });
    assert.equal(context.$fieldForm.option_keys['Niets van toepassing'], 'key-none');
    assert.equal(context.$fieldForm.option_keys.Geen, undefined);
    run("setExclusiveSelection('Niets van toepassing', false)");
    assert.deepEqual(effects(), { 'Niets van toepassing': { exclusive_selection: false } });
});

test('removing an answer removes only its effects', () => {
    const { context, run, effects } = harness();
    run("setExclusiveSelection('A', false); removeOption(1)");
    assert.deepEqual(JSON.parse(context.$fieldForm.options_json), ['A']);
    assert.deepEqual(effects(), { A: { exclusive_selection: false } });
    assert.equal(context.$fieldForm.option_keys.A, 'key-a');
    assert.equal(context.$fieldForm.option_keys.Geen, undefined);
});

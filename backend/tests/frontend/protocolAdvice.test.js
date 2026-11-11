import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url), 'utf8');
const functions = source.slice(source.indexOf('    function adviceSetting('), source.indexOf('    let workingTemplate'));

test('movement edits retain all fields and stay separate from management, including after switching tabs', () => {
    const context = { activeSection: 'beweging', $form: { customer_settings: { movement: [], management: [] } } };
    runInNewContext(functions + '; this.setting = adviceSetting; this.update = updateAdvice;', context);
    const advice = { id: 'movement', title: 'Stappen', description: 'Basisinstructie' };
    context.update(advice, 'note', 'Rustig opbouwen');
    context.update(advice, 'frequency', 'Dagelijks');
    context.update(advice, 'url', 'https://example.com/stappen');
    assert.equal(context.setting(advice).instruction, 'Basisinstructie');
    assert.equal(context.setting(advice).note, 'Rustig opbouwen');
    assert.equal(context.setting(advice).frequency, 'Dagelijks');
    assert.equal(context.$form.customer_settings.management.length, 0);
    context.activeSection = 'management';
    context.update({ id: 'management', title: 'Gebitscontrole', description: 'Controleer' }, 'note', 'Eigen toelichting');
    assert.equal(context.$form.customer_settings.management[0].category, 'care');
    context.activeSection = 'beweging';
    assert.equal(context.setting(advice).note, 'Rustig opbouwen');
    assert.equal(context.$form.customer_settings.movement.length, 1);
    assert.equal(context.setting(advice).url, 'https://example.com/stappen');
});


test('nutrition instructions can be customized without changing movement or management', () => {
    const context = { activeSection: 'voeding', $form: { customer_settings: { nutrition_advice: [], movement: [], management: [] } } };
    runInNewContext(functions + '; this.setting = adviceSetting; this.update = updateAdvice;', context);
    const advice = { id: 'nutrition', description: 'Standaardtekst' };
    assert.equal(context.setting(advice).instruction, 'Standaardtekst');
    context.update(advice, 'instruction', 'Eigen instructie voor dit paard.');
    assert.equal(context.setting(advice).instruction, 'Eigen instructie voor dit paard.');
    assert.equal(advice.description, 'Standaardtekst');
    assert.equal(context.$form.customer_settings.movement.length, 0);
    assert.equal(context.$form.customer_settings.management.length, 0);
});

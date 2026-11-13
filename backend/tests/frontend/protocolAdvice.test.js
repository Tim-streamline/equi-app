import assert from 'node:assert/strict';
import test from 'node:test';
import { adviceSetting, updatedAdviceSettings } from '../../resources/js/lib/protocolAdvice.js';

// Mirrors the editor: each edit replaces the section's rows in the form's customer settings.
function editor(customerSettings) {
    const form = { customer_settings: customerSettings };
    return {
        form,
        setting: (section, advice) => adviceSetting(form.customer_settings, section, advice),
        update: (section, advice, key, value) => {
            form.customer_settings = { ...form.customer_settings, ...updatedAdviceSettings(form.customer_settings, section, advice, key, value) };
        },
    };
}

test('movement edits retain all fields and stay separate from management', () => {
    const { form, setting, update } = editor({ nutrition_advice: [], movement: [], management: [] });
    const advice = { id: 'movement', title: 'Stappen', description: 'Basisinstructie' };
    update('beweging', advice, 'note', 'Rustig opbouwen');
    update('beweging', advice, 'frequency', 'Dagelijks');
    update('beweging', advice, 'url', 'https://example.com/stappen');
    assert.equal(setting('beweging', advice).instruction, 'Basisinstructie');
    // Editing other fields must not pin the library text as a custom instruction.
    assert.equal(form.customer_settings.movement[0].instruction, null);
    assert.equal(form.customer_settings.management.length, 0);
    update('management', { id: 'management', title: 'Gebitscontrole', description: 'Controleer' }, 'note', 'Eigen toelichting');
    assert.equal(form.customer_settings.management[0].category, 'care');
    assert.equal(form.customer_settings.movement.length, 1);
    assert.deepEqual(
        (({ note, frequency, url }) => ({ note, frequency, url }))(setting('beweging', advice)),
        { note: 'Rustig opbouwen', frequency: 'Dagelijks', url: 'https://example.com/stappen' },
    );
});

test('nutrition instructions can be customized without changing movement or management', () => {
    const { form, setting, update } = editor({ nutrition_advice: [], movement: [], management: [] });
    const advice = { id: 'nutrition', description: 'Standaardtekst' };
    assert.deepEqual(setting('voeding', advice), { id: 'nutrition', instruction: 'Standaardtekst' });
    update('voeding', advice, 'instruction', 'Eigen instructie voor dit paard.');
    assert.equal(setting('voeding', advice).instruction, 'Eigen instructie voor dit paard.');
    assert.equal(advice.description, 'Standaardtekst');
    assert.equal(form.customer_settings.movement.length, 0);
    assert.equal(form.customer_settings.management.length, 0);
});

test('a cleared instruction shows the library text the customer sees, while an in-progress empty one stays empty', () => {
    const { form, setting, update } = editor({
        nutrition_advice: [{ id: 'nutrition', instruction: null }],
        movement: [{ id: 'movement', action: 'avoid', instruction: null }],
        management: [],
    });
    const nutrition = { id: 'nutrition', description: 'Standaardtekst' };
    const movement = { id: 'movement', description: 'Bewegingstekst' };
    assert.equal(setting('voeding', nutrition).instruction, 'Standaardtekst');
    update('voeding', nutrition, 'instruction', '');
    assert.equal(setting('voeding', nutrition).instruction, '');
    assert.equal(setting('beweging', movement).instruction, 'Bewegingstekst');
    assert.equal(setting('beweging', movement).action, 'avoid');
    update('beweging', movement, 'note', 'Alleen de toelichting');
    assert.equal(form.customer_settings.movement[0].instruction, null);
    assert.equal(form.customer_settings.movement[0].note, 'Alleen de toelichting');
});

import assert from 'node:assert/strict';
import test from 'node:test';
import {
    adviceErrorTarget, adviceSetting, adviceSettingErrors, snapshotInstruction, unofferedAdvice, updatedAdviceSettings,
} from '../../resources/js/lib/protocolAdvice.js';

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

test('an edit keeps its row in place, so validation errors keep pointing at the advice they were reported for', () => {
    const { form, update } = editor({
        nutrition_advice: [],
        movement: [{ id: 'a', action: 'do', url: 'www.example.com' }, { id: 'b', action: 'do', url: '' }],
        management: [],
    });
    const errors = { 'customer_settings.movement.0.url': 'Ongeldige link.', 'customer_settings.movement.0': 'Ongeldige rij.' };
    const [a, b, c] = ['a', 'b', 'c'].map((id) => ({ id, description: '' }));
    update('beweging', a, 'note', 'Bewerkt na een mislukte opslag');
    update('beweging', c, 'note', 'Nieuw');

    assert.deepEqual(form.customer_settings.movement.map((row) => row.id), ['a', 'b', 'c']);
    assert.deepEqual(adviceSettingErrors(errors, form.customer_settings, 'beweging', a), { url: 'Ongeldige link.', row: 'Ongeldige rij.' });
    assert.deepEqual(adviceSettingErrors(errors, form.customer_settings, 'beweging', b), {});
    assert.deepEqual(adviceSettingErrors(errors, form.customer_settings, 'voeding', a), {});
});

test('advice that is no longer offered stays reachable while it is selected or its kept row has errors', () => {
    const settings = {
        nutrition_advice: [],
        management: [],
        movement: [{ id: 'offered', url: 'x' }, { id: 'archived-invalid', url: 'x' }, { id: 'archived-valid', url: '' }],
    };
    const errors = { 'customer_settings.movement.0.url': 'Ongeldig.', 'customer_settings.movement.1.url': 'Ongeldig.' };
    const items = [{ id: 'offered', title: 'Aangeboden' }];
    const known = [{ id: 'archived-invalid', title: 'Stappen', description: 'Tekst' }];

    assert.deepEqual(unofferedAdvice(errors, settings, 'beweging', items, ['offered', 'archived-selected'], known), [
        { title: 'Niet meer beschikbaar advies', description: '', id: 'archived-selected', unoffered: true },
        { title: 'Stappen', description: 'Tekst', id: 'archived-invalid', unoffered: true },
    ]);
    assert.deepEqual(unofferedAdvice({}, settings, 'beweging', items, ['offered']), []);
});

test('an error path resolves to its section and advice', () => {
    const settings = { nutrition_advice: [{ id: 'n' }], management: [], movement: [{ id: 'a' }, { id: 'b' }] };
    assert.deepEqual(adviceErrorTarget('customer_settings.movement.1.url', settings), { section: 'beweging', id: 'b' });
    assert.deepEqual(adviceErrorTarget('customer_settings.nutrition_advice.0', settings), { section: 'voeding', id: 'n' });
    assert.deepEqual(adviceErrorTarget('customer_settings.management', settings), { section: 'management', id: null });
    assert.equal(adviceErrorTarget('customer_settings.weight_kg', settings), null);
    assert.equal(adviceErrorTarget('movement.1.url', settings), null);
});

test('the protocol page shows the instruction the customer gets', () => {
    const settings = {
        nutrition_advice: [{ id: 'source-n', instruction: 'Eigen voeding' }],
        management: [{ id: 'source-m', instruction: null }],
        movement: [{ id: 'source-b', instruction: '' }],
    };
    const snapshot = (section, source) => ({ id: `snapshot-${source}`, [`${section}_advies_id`]: source, description: 'Kopie' });
    assert.equal(snapshotInstruction(settings, 'voeding', snapshot('voeding', 'source-n')), 'Eigen voeding');
    assert.equal(snapshotInstruction(settings, 'management', snapshot('management', 'source-m')), 'Kopie');
    // An empty instruction is what the customer dashboard shows too.
    assert.equal(snapshotInstruction(settings, 'beweging', snapshot('beweging', 'source-b')), '');
    assert.equal(snapshotInstruction(settings, 'beweging', snapshot('beweging', 'other')), 'Kopie');
    assert.equal(snapshotInstruction(null, 'voeding', snapshot('voeding', 'source-n')), 'Kopie');
});

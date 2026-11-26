import test from 'node:test';
import assert from 'node:assert/strict';
import { labelDefinitions, editIntakeLabel, submittedIntakeLabels } from '../../resources/js/lib/intakeLabels.js';

test('current admin wording and nested answer options are used without a static catalog', () => {
    const nodes = labelDefinitions({ type: 'repeater', label: 'Mijn actuele vraag', sub: [{ id: 'plaats', type: 'multi', label: 'Mijn subvraag', options: ['Ja', 'Ja ', { value: 'Ja' }, 'Ja'] }] });
    assert.equal(nodes[0].title, 'Mijn actuele vraag');
    assert.deepEqual(nodes[1], { key: 'plaats', title: 'Mijn subvraag', options: ['Ja', 'Ja '] });
    assert.deepEqual(labelDefinitions({ type: 'sectionhead' }), []);
    assert.deepEqual(labelDefinitions({ type: 'multi', options: ['Ja', ' Ja '] })[0].options, ['Ja']);
});

test('editing one answer preserves independent labels and does not mutate original data', () => {
    const config = [{ key: '', label: 'woonvorm', answers: [{ answer: 'Binnen', label: 'binnen' }, { answer: 'Buiten', label: 'buiten' }] }];
    const changed = editIntakeLabel(config, '', 'Buiten', 'woonvorm_buiten');
    assert.equal(changed[0].answers[1].label, 'woonvorm_buiten');
    assert.equal(changed[0].answers[0].label, 'binnen');
    assert.equal(config[0].answers[1].label, 'buiten');
});

test('a new nested answer label survives submission before its question default exists', () => {
    const config = editIntakeLabel([], 'plaats', 'Buiten', 'buiten');
    assert.deepEqual(submittedIntakeLabels(config), [{ key: 'plaats', label: null, answers: [{ answer: 'Buiten', label: 'buiten' }] }]);
});

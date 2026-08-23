import assert from 'node:assert/strict';
import test from 'node:test';

import {
    defaultProtocolSupplementDosage,
    defaultProtocolSupplementInstructions,
} from '../../resources/js/lib/protocolDosage.js';

test('fills a calculated per-600-kg dosage when an existing instance dosage is empty', () => {
    assert.equal(defaultProtocolSupplementDosage('', {
        dosis_type: 'per_600_kg',
        dosis: 3,
        unit: 'eetlepel',
    }, 550), '2.75 eetlepel');
});

test('keeps a manually corrected instance dosage', () => {
    assert.equal(defaultProtocolSupplementDosage('2 eetlepel', {
        dosis_type: 'per_600_kg',
        dosis: 3,
        unit: 'eetlepel',
    }, 550), '2 eetlepel');
});

test('leaves a weight-based dosage empty when the horse has no valid weight', () => {
    assert.equal(defaultProtocolSupplementDosage(null, {
        dosis_type: 'per_kg',
        dosis: 0.32,
        unit: 'g',
    }, null), '');
});

test('fills template instructions when an instance instruction is empty', () => {
    assert.equal(defaultProtocolSupplementInstructions('', {
        instructions: 'Goed door het voer mengen.',
    }), 'Goed door het voer mengen.');
});

test('keeps manually corrected instance instructions', () => {
    assert.equal(defaultProtocolSupplementInstructions('Alleen bij de avondvoeding.', {
        instructions: 'Goed door het voer mengen.',
    }), 'Alleen bij de avondvoeding.');
});

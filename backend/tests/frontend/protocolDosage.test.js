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

const { templateDosage, recalculateProtocolDosages } = await import('../../resources/js/lib/protocolDosage.js');
test('scales the complete 535 kg intake weight before rounding grams upward', () => {
    for (const [dose, expected] of [[100, '90 g'], [15, '15 g'], [200, '180 g']]) {
        assert.equal(templateDosage({ dosis: dose, dosis_type: 'per_600_kg', unit: 'g' }, 535), expected);
    }
});
test('rounding respects the 10 g boundary and exact multiples across weights', () => {
    for (const [dose, expected] of [[1.35, '2.5 g'], [3.8, '5 g'], [7.2, '7.5 g'], [9.1, '10 g'], [10, '10 g'], [13.375, '15 g'], [42.5, '45 g'], [89.17, '90 g'], [100, '100 g']]) {
        assert.equal(templateDosage({ dosis: dose, dosis_type: 'per_600_kg', unit: 'g' }, 600), expected);
    }
    assert.equal(templateDosage({ dosis: 100, dosis_type: 'per_600_kg', unit: 'g' }, 450), '75 g');
    assert.equal(templateDosage({ dosis: 100, dosis_type: 'per_600_kg', unit: 'g' }, 750), '125 g');
    assert.equal(templateDosage({ dosis: 3, dosis_type: 'per_600_kg', unit: 'eetlepel' }, 535), '2.675 eetlepel');
});
test('horse or actual-weight changes recalculate automatic doses but preserve manual ones', () => {
    const automatic = { dosis: 100, dosis_type: 'per_600_kg', unit: 'g', dosage_mode: 'automatic', dosage: '90 g', aantal_per_week: 4 };
    const manual = { ...automatic, dosage_mode: 'manual', dosage: '82 g persoonlijk' };
    const phases = [{ supplements: [automatic, manual] }];
    const result = recalculateProtocolDosages(phases, 600);
    assert.equal(result[0].supplements[0].dosage, '100 g');
    assert.equal(result[0].supplements[1].dosage, '82 g persoonlijk');
    assert.equal(result[0].supplements[0].aantal_per_week, 4);
    automatic.aantal_per_week = 7;
    assert.equal(recalculateProtocolDosages(phases, 600)[0].supplements[0].dosage, '100 g');
});

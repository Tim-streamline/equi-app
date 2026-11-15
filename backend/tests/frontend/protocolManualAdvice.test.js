import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    emptyManualAdvice,
    keepPlannedPhases,
    manualAdviceDraftErrors,
    manualAdviceErrors,
    manualAdviceForCategory,
    manualAdviceFromProtocol,
    needsPhaseChoice,
    phaseSummary,
    removeManualAdvice,
    saveManualAdvice,
} from '../../resources/js/lib/protocolManualAdvice.js';

const phases = [{ client_key: 'p1', title: 'Opstart' }, { client_key: 'p2', title: 'Opbouw' }];

test('stored manual advice becomes form rows that refer to phases by their id', () => {
    const [row] = manualAdviceFromProtocol([{ id: 'a1', category: 'management', title: 'T', description: 'D', management_category: 'care', action: 'avoid', applies_to: 'phases', phases: [{ id: 'p2' }] }]);
    assert.deepEqual(row, { id: 'a1', client_key: 'a1', category: 'management', title: 'T', description: 'D', management_category: 'care', action: 'avoid', applies_to: 'phases', phase_keys: ['p2'] });
});

test('a draft is added, edited in place and removed', () => {
    const draft = { ...emptyManualAdvice('beweging'), title: ' Rustig stappen ', description: ' Elke dag. ' };
    assert.equal(draft.id, null);
    assert.equal(draft.management_category, null);
    let rows = saveManualAdvice([], draft);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].title, 'Rustig stappen');

    rows = saveManualAdvice(rows, { ...rows[0], title: 'Stappen', applies_to: 'phases', phase_keys: ['p1', 'p1'] });
    assert.equal(rows.length, 1);
    assert.equal(rows[0].title, 'Stappen');
    assert.deepEqual(rows[0].phase_keys, ['p1']);

    // Back to the whole protocol drops the phase links.
    rows = saveManualAdvice(rows, { ...rows[0], applies_to: 'protocol' });
    assert.deepEqual(rows[0].phase_keys, []);

    assert.deepEqual(removeManualAdvice(rows, rows[0].client_key), []);
});

test('removing a phase never turns phase advice into whole-protocol advice', () => {
    const rows = [
        { ...emptyManualAdvice('voeding'), client_key: 'a', applies_to: 'phases', phase_keys: ['p1', 'p2'] },
        { ...emptyManualAdvice('voeding'), client_key: 'b', applies_to: 'phases', phase_keys: ['p2'] },
        { ...emptyManualAdvice('voeding'), client_key: 'c', applies_to: 'protocol', phase_keys: [] },
    ];
    const kept = keepPlannedPhases(rows, [phases[0]]);
    assert.deepEqual(kept.map((row) => [row.applies_to, row.phase_keys]), [['phases', ['p1']], ['phases', []], ['protocol', []]]);
    assert.equal(kept[2], rows[2]);
    assert.deepEqual(kept.map(needsPhaseChoice), [false, true, false]);
    assert.equal(phaseSummary(kept[1], [phases[0]]), 'Geen fase gekozen');
    assert.equal(phaseSummary(kept[0], phases), 'Opstart');
    assert.equal(phaseSummary(kept[2], phases), 'Hele protocol');
});

test('draft validation mirrors the server rules', () => {
    const draft = { ...emptyManualAdvice('management'), management_category: '', applies_to: 'phases' };
    assert.deepEqual(Object.keys(manualAdviceDraftErrors(draft)).sort(), ['description', 'management_category', 'phase_keys', 'title']);
    assert.deepEqual(manualAdviceDraftErrors({ ...draft, title: 'T', description: 'D', management_category: 'care', phase_keys: ['p1'] }), {});
});

test('rows are listed per category and server errors are found by row', () => {
    const rows = [{ client_key: 'a', category: 'voeding' }, { client_key: 'b', category: 'beweging' }];
    assert.deepEqual(manualAdviceForCategory(rows, 'beweging'), [rows[1]]);
    assert.deepEqual(manualAdviceErrors({ 'manual_advice.1.phase_keys': 'Kies een fase', 'manual_advice.10.title': 'x', title: 'y' }, rows, 'b'), { phase_keys: 'Kies een fase' });
    assert.deepEqual(manualAdviceErrors({}, rows, 'missing'), {});
});

test('the editor offers manual advice in every advice tab and keeps it in sync with the plan', () => {
    const editor = readFileSync(new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url), 'utf8');

    assert.match(editor, /<Plus class="size-4" \/> Handmatig advies toevoegen/);
    assert.match(editor, /manual_advice: manualAdviceFromProtocol\(initialProtocol\?\.manual_adviezen \?\? \[\]\)/);
    assert.equal(editor.match(/\$form\.manual_advice = keepPlannedPhases\(/g)?.length, 2);
});

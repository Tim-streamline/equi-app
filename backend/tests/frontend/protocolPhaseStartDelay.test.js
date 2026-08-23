import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { protocolPhaseRanges, totalProtocolWeeks } from '../../resources/js/lib/protocolPhasePlanning.js';

const templateSource = readFileSync(
    new URL('../../resources/js/Pages/ProtocolSettings/Index.svelte', import.meta.url),
    'utf8',
);
const protocolSource = readFileSync(
    new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url),
    'utf8',
);

test('template phases expose an optional completed-weeks setting', () => {
    assert.match(templateSource, /start_after_previous_phase_weeks/);
    assert.match(templateSource, /Afwijkende start/);
    assert.match(templateSource, /Start na aantal complete weken vorige fase/);
});

test('protocol phase instances copy and expose the delayed-start setting', () => {
    assert.match(protocolSource, /start_after_previous_phase_weeks:\s*definition\.start_after_previous_phase_weeks/);
    assert.match(protocolSource, /Afwijkende start/);
    assert.match(protocolSource, /Start na aantal complete weken vorige fase/);
});

test('a configured phase starts after completed weeks within the previous phase', () => {
    const phases = [
        { week_count: 4, start_after_previous_phase_weeks: null },
        { week_count: 2, start_after_previous_phase_weeks: 2 },
        { week_count: 2, start_after_previous_phase_weeks: null },
    ];

    assert.deepEqual(protocolPhaseRanges(phases), [
        { start: 1, end: 4 },
        { start: 3, end: 4 },
        { start: 5, end: 6 },
    ]);
    assert.equal(totalProtocolWeeks(phases), 6);
});

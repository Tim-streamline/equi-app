import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import {
    defaultProtocolSupplementDosage,
    defaultProtocolSupplementInstructions,
    templateDosage,
} from '../../resources/js/lib/protocolDosage.js';

const source = readFileSync(new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url), 'utf8');
// Execute the editor's actual catalog-to-form initialization, including both mapping steps.
const start = source.indexOf('    function catalogFromTemplate');
const end = source.indexOf('    const protocolTemplateName');
assert.ok(start >= 0 && end > start);
const initialization = source.slice(start, end);
const none = 'f5907240-5f13-4f87-a032-000000000001';
const linear = 'f5907240-5f13-4f87-a032-000000000007';
const custom = 'f5907240-5f13-4f87-a032-000000000003';

function initialize(buildupId, protocol = null) {
    const context = {
        initialProtocol: protocol,
        initialProtocolTemplates: [{ id: 'template', phases: [{
            id: 'phase', name: 'Phase', required: true, weeks: [{ id: 'week', number: 1 }],
            supplements: [{ id: 'supplement', name: 'Herb', dose_buildup_id: buildupId,
                add_by_default: true, dosis_type: 'per_600_kg', dosis: 20, unit: 'g', weeks: [{ number: 1 }] }],
        }] }],
        initialHorses: [{ id: 'horse', weight_kg: 600 }], initialSelectedHorseId: 'horse',
        intakeWeights: {}, noBuildupId: none, makeClientKey: () => 'key', untrack: (fn) => fn(),
        templateDosage, defaultProtocolSupplementDosage, defaultProtocolSupplementInstructions,
    };
    return runInNewContext(`${initialization}\ninitialPhases[0].supplements[0];`, context);
}

test('initial new-protocol form retains the template buildup for default supplements', () => {
    for (const id of [linear, custom, none]) {
        const selection = initialize(id);
        assert.equal(selection.dose_buildup_id, id);
        assert.equal(selection.dosage, '20 g');
        assert.equal(selection.dosage_mode, 'automatic');
    }
});

test('an intake draft without phases inherits the template buildup', () => {
    assert.equal(initialize(custom, { id: 'draft', horse_id: 'horse', phases: [] }).dose_buildup_id, custom);
});

test('existing protocol snapshots and legacy no-buildup selections survive catalog changes', () => {
    const protocol = (snapshot) => ({
        id: 'protocol', horse_id: 'horse', protocol_template_id: 'template',
        phases: [{ id: 'saved-phase', protocol_template_phase_id: 'phase', week_count: 1,
            supplements: [{ id: 'selection', supplement_id: 'supplement', dosage: '20 g', dose_buildup: snapshot }] }],
    });
    assert.equal(initialize(custom, protocol({ id: linear })).dose_buildup_id, linear);
    assert.equal(initialize(custom, protocol(null)).dose_buildup_id, none);
});

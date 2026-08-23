import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const protocolEditSource = readFileSync(
    new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url),
    'utf8',
);

test('protocol instance instructions use the same three-row textarea as the template', () => {
    const instructionField = protocolEditSource.match(
        /<Field label="Instructies"[\s\S]*?<\/Field>/,
    )?.[0];

    assert.ok(instructionField, 'The protocol supplement instructions field should exist.');
    assert.match(instructionField, /<Textarea\b/);
    assert.match(instructionField, /rows="3"/);
});

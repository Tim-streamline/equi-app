import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const protocolEditSource = readFileSync(
    new URL('../../resources/js/Pages/Protocols/Edit.svelte', import.meta.url),
    'utf8',
);

test('enabled supplement switch keeps its thumb inside the track', () => {
    const supplementSwitch = protocolEditSource.match(
        /aria-label=\{`\$\{selected \? 'Verwijder' : 'Voeg toe'\}[\s\S]*?<\/button>/,
    )?.[0];

    assert.ok(supplementSwitch, 'The supplement enablement switch should exist.');
    assert.match(supplementSwitch, /left-\[2\.5px\]/);
    assert.match(supplementSwitch, /selected \? 'translate-x-\[17px\]'/);
    assert.doesNotMatch(supplementSwitch, /translate-x-\[19px\]/);
});

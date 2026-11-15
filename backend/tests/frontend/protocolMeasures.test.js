import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { measureMatches, normalizeSearch, protocolCount } from '../../resources/js/lib/protocolMeasures.js';

const measure = { title: 'Ruimte en uitwijkmogelijkheden creëren', description: 'Voorkom nauwe doorgangen tussen PAARDEN.' };

test('search ignores case and diacritics and matches title or description', () => {
    assert.equal(normalizeSearch('  Creëren '), 'creeren');
    assert.ok(measureMatches(measure, 'creeren'));
    assert.ok(measureMatches(measure, 'paarden doorgangen'));
    assert.ok(measureMatches(measure, '   '));
    assert.ok(!measureMatches(measure, 'ruwvoer'));
    assert.ok(!measureMatches(measure, 'paarden ruwvoer'));
});

test('protocol counts use the Dutch singular and plural', () => {
    assert.equal(protocolCount(1), '1 protocol');
    assert.equal(protocolCount(3), '3 protocollen');
});

test('measure page only offers reordering for the full active list and guards used measures', () => {
    const source = readFileSync(new URL('../../resources/js/Pages/ProtocolMeasures/Index.svelte', import.meta.url), 'utf8');

    assert.match(source, /const canReorder = \$derived\(statusFilter === 'active' && query\.trim\(\) === ''\)/);
    assert.match(source, /disabled=\{measure\.usage_count > 0\}/);
    assert.match(source, /disabled=\{categoryLocked\}/);
    assert.match(source, /\/admin\/protocol-measures\/\$\{measure\.category\}\/\$\{measure\.id\}\/move/);
});

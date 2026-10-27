import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/lib/components/IntakeConditionEditor.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');
const questions = [
    { key: 'huisvesting.leefruimte-paarden', type: 'number', options: ['Weet ik niet'] },
    { key: 'huisvesting.leeftijd', type: 'number' },
    { key: 'paard.geslacht', type: 'radio', options: ['merrie', 'ruin'] },
];

function editor(value) {
    const saved = [];
    const run = new Function('$props', '$derived', `${script}
        return { initialRule, minimumRule, modeRule, isMinimum, minimumMode };`);
    const api = run(() => ({ value, questions, onchange: rule => saved.push(rule) }), v => v);
    return { ...api, saved };
}

test('a number question starts as a visible, valid minimum instead of an option match', () => {
    const { initialRule } = editor(null);
    assert.deepEqual(initialRule('huisvesting.leefruimte-paarden'), { 'huisvesting.leefruimte-paarden': { gte: 1 } });
    assert.deepEqual(initialRule('huisvesting.leeftijd'), { 'huisvesting.leeftijd': { gte: 1 } });
    assert.deepEqual(initialRule('paard.geslacht'), { 'paard.geslacht': 'merrie' });
});

test('clearing the minimum stays empty so the server refuses it, never 0', () => {
    const { minimumRule } = editor({ 'huisvesting.leefruimte-paarden': { gte: 2 } });
    assert.deepEqual(minimumRule(''), { 'huisvesting.leefruimte-paarden': { gte: '' } });
    assert.deepEqual(minimumRule('3'), { 'huisvesting.leefruimte-paarden': { gte: 3 } });
    assert.deepEqual(minimumRule('0'), { 'huisvesting.leefruimte-paarden': { gte: 0 } });
});

test('an option rule on a number question is shown as options and can be switched explicitly', () => {
    const optionRule = editor({ 'huisvesting.leefruimte-paarden': 'Weet ik niet' });
    assert.equal(optionRule.minimumMode, false);
    assert.deepEqual(optionRule.modeRule('gte'), { 'huisvesting.leefruimte-paarden': { gte: 1 } });
    const minimum = editor({ 'huisvesting.leefruimte-paarden': { gte: 2 } });
    assert.equal(minimum.minimumMode, true);
    assert.deepEqual(minimum.modeRule('options'), { 'huisvesting.leefruimte-paarden': 'Weet ik niet' });
});

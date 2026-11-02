import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import { buildIntakeSchema, resolveAnswerConditions } from '../lib/intake/config.ts';
const modules = {};
for (const name of ['dates', 'schema', 'config', 'logic']) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(`../lib/intake/${name}.ts`, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('require', 'exports', code)(key => { assert.ok(key in modules, key); return modules[key]; }, exports);
  modules[`./${name}`] = exports;
}
const { showField, effectiveAnswers } = modules['./logic'];
const aKey = '00000000-0000-4000-8000-000000000001';
const bKey = '00000000-0000-4000-8000-000000000002';
const otherKey = '00000000-0000-4000-8000-000000000003';
function schema(rule) {
  return buildIntakeSchema([{ id: 'one', key: 'one', title: 'One' }, { id: 'two', key: 'two', title: 'Two' }], [
    { section_id: 'one', key: 'a', label: 'A', type: 'multi', options: '["Renamed","Other"]', option_keys: JSON.stringify({ Renamed: aKey, Other: otherKey }), answer_aliases: '{"Old wording":"Renamed"}', answer_section_key: 'original' },
    { section_id: 'two', key: 'b', label: 'B', type: 'radio', options: '["Yes"]', option_keys: JSON.stringify({ Yes: bKey }) },
    { section_id: 'two', key: 'detail', label: 'Detail', type: 'text', visibility_rule: JSON.stringify(rule), show_if: '{"outdated":"do not use"}' },
  ]);
}

test('global answer conditions support cross-question AND, OR, and nesting', () => {
  const a = { $answer: aKey }, b = { $answer: bKey }, other = { $answer: otherKey };
  for (const [rule, selectedA, selectedB, expected] of [
    [{ $all: [a, b] }, ['Renamed'], 'Yes', true],
    [{ $all: [a, b] }, ['Other'], 'Yes', false],
    [{ $all: [a, other] }, ['Renamed', 'Other'], '', true],
    [{ $all: [a, other] }, ['Renamed'], '', false],
    [{ $any: [a, b] }, [], 'Yes', true],
    [{ $any: [a, b] }, [], '', false],
    [{ $all: [b, { $any: [a, other] }] }, ['Other'], 'Yes', true],
    [a, ['Old wording'], '', true],
  ]) {
    const configured = schema(rule);
    const field = configured[1].fields[1];
    const answers = { original: { a: selectedA }, two: { b: selectedB } };
    assert.equal(showField(field, answers.two, answers, ['Renamed', 'Yes', 'Other']), expected);
  }
});

test('hidden parents and missing global answer keys cannot activate followups', () => {
  const configured = schema({ $answer: aKey });
  configured[0].fields[0].showIf = { 'two.b': 'Yes' };
  const answers = { original: { a: ['Renamed'] }, two: { b: '', detail: 'Stale detail' } };
  const effective = effectiveAnswers(answers, configured, []);
  assert.equal(effective.original.a, undefined);
  assert.equal(effective.two.detail, undefined);
  assert.equal(answers.two.detail, 'Stale detail');
  const missing = schema({ $answer: 'missing-key' })[1].fields[1];
  assert.equal(showField(missing, {}, answers, []), false);
});

test('bundled first-start schema resolves keyed visibility rules', () => {
  const bundled = resolveAnswerConditions(modules['./schema'].INTAKE_SCHEMA);
  const fields = bundled.flatMap(section => section.fields);
  assert.ok(fields.some(field => field.visibilityRule));
  assert.ok(fields.every(field => !JSON.stringify(field.showIf ?? {}).includes('"$answer"')));
  const keys = fields.flatMap(field => Object.values(field.optionKeys ?? {}));
  assert.equal(keys.length, new Set(keys).size);
});

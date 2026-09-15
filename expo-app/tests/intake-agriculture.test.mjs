import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const modules = {};
for (const name of ['schema', 'config', 'logic']) {
  const source = readFileSync(new URL(`../lib/intake/${name}.ts`, import.meta.url), 'utf8');
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  });
  const exports = {};
  new Function('require', 'exports', outputText)((dependency) => {
    assert.ok(modules[dependency], `Unexpected dependency: ${dependency}`);
    return modules[dependency];
  }, exports);
  modules[`./${name}`] = exports;
}

const { INTAKE_SCHEMA } = modules['./schema'];
const { buildIntakeSchema } = modules['./config'];
const { visibleFields, missingRequired, isFieldAnswered } = modules['./logic'];
const payload = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url)));
const fallback = INTAKE_SCHEMA.find((section) => section.id === 'huisvesting');
const definition = payload.sections.find((section) => section.id === 'huisvesting');
const synced = buildIntakeSchema(
  [{ id: 'housing-uuid', key: definition.id, title: definition.title }],
  definition.fields.map((field, order) => ({ ...field, section_id: 'housing-uuid', key: field.id, order, show_if: JSON.stringify(field.showIf) })),
)[0];
const block = (section) => ({ ...section, fields: section.fields.filter((field) => field.id.startsWith('landbouw-') || field.id === 'omgeving-overig') });

test('backend and fallback append the same complete agriculture block to housing', () => {
  assert.deepEqual(fallback.fields, definition.fields);
  assert.equal(fallback.fields.at(-1).id, 'omgeving-overig');
  assert.equal(fallback.fields.at(-12).label, 'Omgeving & landbouwpercelen');
  assert.equal(block(fallback).fields.length, 11);
});

for (const [name, section] of [['fallback', fallback], ['synced', synced]]) {
  test(`${name}: no or unknown nearby parcel hides all parcel followups, including stale spray answers`, () => {
    for (const nearby of ['Nee', 'Weet ik niet']) {
      const answers = { 'landbouw-nabij': nearby, 'landbouw-bespoten': 'Ja', 'landbouw-klachten': 'Ja' };
      assert.deepEqual(visibleFields(block(section), answers).map((field) => field.id), ['landbouw-nabij', 'omgeving-overig']);
    }
  });

  test(`${name}: spray and symptom details appear only for the applicable answers`, () => {
    const questions = block(section);
    const answers = { 'landbouw-nabij': 'Ja', 'landbouw-bespoten': 'Weet ik niet', 'landbouw-klachten': 'Nee' };
    const ids = () => visibleFields(questions, answers).map((field) => field.id);
    assert.ok(ids().includes('landbouw-afstand'));
    assert.ok(ids().includes('landbouw-nevel'));
    assert.ok(ids().includes('landbouw-water'));
    assert.ok(!ids().includes('landbouw-middelen'));
    assert.ok(!ids().includes('landbouw-frequentie'));
    assert.ok(!ids().includes('landbouw-klachten-toelichting'));
    answers['landbouw-bespoten'] = 'Ja';
    answers['landbouw-klachten'] = 'Ja';
    assert.equal(ids().length, 11);
    assert.ok(missingRequired(questions, answers).some((field) => field.id === 'landbouw-klachten-toelichting'));
    const spraying = questions.fields.find((field) => field.id === 'landbouw-middelen');
    assert.equal(isFieldAnswered(spraying, ['Onkruidbestrijding / herbicide', 'Schimmelbestrijding / fungicide']), true);
    assert.equal(isFieldAnswered(spraying, ['not an option']), false);
  });
}

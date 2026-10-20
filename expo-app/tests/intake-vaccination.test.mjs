import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const load = (file, modules = {}) => {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', js)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
};

test('last vaccination accepts a short answer while retaining the saved answer key', () => {
  const schema = load('../lib/intake/schema.ts');
  const config = load('../lib/intake/config.ts');
  const logic = load('../lib/intake/logic.ts', { './schema': schema, './config': config });
  const question = schema.INTAKE_SCHEMA.find(section => section.id === 'medisch').fields.find(field => field.id === 'vacc-laatst');
  const hint = 'Maand en jaartal is voldoende indien je de exacte datum niet weet. Indien niet bekend, vul dan "onbekend" in.';
  assert.equal(question.type, 'text');
  assert.equal(question.hint, hint);
  assert.equal(logic.isFieldAnswered(question, '2026-04-30'), true);
  assert.equal(logic.isFieldAnswered(question, 'april 2026'), true);
  assert.equal(logic.isFieldAnswered(question, 'onbekend'), true);
  assert.equal(logic.isFieldAnswered(question, ''), false);

  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  const snapshot = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire-local.json', import.meta.url), 'utf8'));
  const seeded = seed.sections.find(section => section.id === 'medisch').fields.find(field => field.id === 'vacc-laatst');
  const synced = snapshot.sections.find(section => section.key === 'medisch').fields.find(field => field.key === 'vacc-laatst');
  for (const field of [seeded, synced]) {
    assert.equal(field.type, 'text');
    assert.equal(field.hint, hint);
  }
  const legacy = config.buildIntakeSchema(
    [{ id: 'section-id', key: 'medisch', title: 'Medisch', order: 5 }],
    [{ section_id: 'section-id', key: 'vacc-laatst', label: question.label, type: 'date', hint: null }],
  )[0].fields[0];
  assert.equal(legacy.id, 'vacc-laatst');
  assert.equal(legacy.type, 'text');
  assert.equal(legacy.hint, hint);
});

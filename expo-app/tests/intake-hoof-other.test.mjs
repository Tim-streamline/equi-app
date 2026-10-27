import * as intakeDates from '../lib/intake/dates.ts';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const load = (file, modules = {}) => {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', js)(name => { if (name === './dates' || name === '@/lib/intake/dates') return intakeDates; if (name === './MonthYearField') return { MonthYearField: 'MonthYearField' }; assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
};

const schema = load('../lib/intake/schema.ts');
const config = load('../lib/intake/config.ts');
const logic = load('../lib/intake/logic.ts', { './schema': schema, './config': config });

function check(section) {
  const explanation = section.fields.find(field => field.id === 'hoeven-anders');
  assert.equal(explanation.label, 'Licht je antwoord toe');
  assert.equal(explanation.type, 'text');
  assert.equal(logic.showField(explanation, { 'hoeven-bijz': ['anders'] }), true);
  assert.equal(logic.showField(explanation, { 'hoeven-bijz': ['geen'] }), false);
  assert.equal(logic.showField(explanation, { 'hoeven-bijz': ['rotstraal'] }), false);
  assert.equal(logic.missingRequired(section, { 'hoeven-bijz': ['anders'] }).some(field => field.id === 'hoeven-anders'), true);
  assert.equal(logic.missingRequired(section, { 'hoeven-bijz': ['anders'], 'hoeven-anders': 'Een andere afwijking' }).some(field => field.id === 'hoeven-anders'), false);
  assert.equal(logic.missingRequired(section, { 'hoeven-bijz': ['geen'], 'hoeven-anders': '' }).some(field => field.id === 'hoeven-anders'), false);
}

test('Anders at the hoof question opens a required explanation that closes when deselected', () => {
  check(schema.INTAKE_SCHEMA.find(section => section.id === 'medisch'));
  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  check(seed.sections.find(section => section.id === 'medisch'));
});

test('other mineral sources open the existing short explanation for both label capitalisations', () => {
  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  for (const section of [schema.INTAKE_SCHEMA, seed.sections].map(sections => sections.find(s => s.id === 'voer'))) {
    const field = section.fields.find(f => f.id === 'mineralen-toegang-anders');
    assert.equal(field.type, 'text');
    assert.equal(field.label, 'Licht je antwoord toe.');
    for (const option of ['anders', 'Anders', 'anders, namelijk', 'Anders, namelijk']) {
      const answers = { voer: { 'mineralen-toegang': ['MSM (zwavel)', option] } };
      assert.equal(logic.showField(field, answers.voer, answers), true);
    }
    assert.equal(logic.showField(field, { 'mineralen-toegang': ['geen'] }, { voer: { 'mineralen-toegang': ['geen'] } }), false);
    assert.equal(logic.showField(field, { 'mineralen-toegang': ['MSM (zwavel)'] }, { voer: { 'mineralen-toegang': ['MSM (zwavel)'] } }), false);
  }
});

import * as intakeDates from '../lib/intake/dates.ts';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function load(file, modules = {}) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', js)(name => { if (name === './dates' || name === '@/lib/intake/dates') return intakeDates; if (name === './MonthYearField') return { MonthYearField: 'MonthYearField' }; assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}

const schema = load('../lib/intake/schema.ts');
const config = load('../lib/intake/config.ts');
const logic = load('../lib/intake/logic.ts', { './schema': schema, './config': config });
const ids = ['ems-diagnose-basis', 'ems-diagnose-basis-anders', 'ems-vermoeden-toelichting'];
const confirmed = ['Vastgesteld door dierenarts/onderzoek', 'Ja, vastgesteld door dierenarts/onderzoek'];
const suspected = ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf'];

function check(section) {
  const field = id => section.fields.find(item => item.id === id);
  const visible = answers => logic.visibleFields(section, answers).map(item => item.id);
  const missing = answers => logic.missingRequired(section, answers).map(item => item.id);
  assert.deepEqual(field('ems-diagnose-basis').options, [
    'Vastgestelde insulinedysregulatie / insulineresistentie',
    'Overgewicht of plaatselijke vetophopingen',
    'Eerdere of terugkerende hoefbevangenheid',
    'Beoordeling door een dierenarts',
    'Anders, namelijk',
    'Weet ik niet',
  ]);
  assert.equal(field('ems-vermoeden-toelichting').type, 'textarea');
  assert.equal(field('ems-vermoeden-toelichting').lines, 2);
  assert.equal(field('ems-vermoeden-toelichting').placeholder, 'Beschrijf kort welke klachten, kenmerken of eerdere onderzoeken aanleiding geven tot je vermoeden.');
  assert.equal(field('ems-vermoeden-toelichting').signalConfig.condition, 'EMS');
  for (const status of confirmed) {
    const answers = { 'ems-status': status };
    assert.deepEqual(ids.filter(id => visible(answers).includes(id)), ['ems-diagnose-basis']);
    assert.ok(missing(answers).includes('ems-diagnose-basis'));
    assert.ok(!missing({ ...answers, 'ems-diagnose-basis': ['Weet ik niet'] }).includes('ems-diagnose-basis'));
    assert.deepEqual(ids.filter(id => visible({ ...answers, 'ems-diagnose-basis': ['Anders, namelijk'] }).includes(id)),
      ['ems-diagnose-basis', 'ems-diagnose-basis-anders']);
    assert.ok(missing({ ...answers, 'ems-diagnose-basis': ['Anders, namelijk'] }).includes('ems-diagnose-basis-anders'));
  }
  for (const status of suspected) {
    const answers = { 'ems-status': status, 'ems-diagnose-basis': ['Anders, namelijk'] };
    assert.deepEqual(ids.filter(id => visible(answers).includes(id)), ['ems-vermoeden-toelichting']);
    assert.ok(missing(answers).includes('ems-vermoeden-toelichting'));
    assert.ok(!missing({ ...answers, 'ems-vermoeden-toelichting': 'Een harde vetkam' }).includes('ems-vermoeden-toelichting'));
    assert.ok(!visible({ ...answers, 'ems-vermoeden-redenen': ['Anders, namelijk'] }).includes('ems-vermoeden-anders'));
  }
  assert.deepEqual(ids.filter(id => visible({ 'ems-status': 'Voor zover ik weet niet', 'ems-vermoeden-redenen': ['Anders, namelijk'] }).includes(id)), []);
}

test('EMS keeps confirmed-diagnosis choices and uses one open owner-suspicion field', () => {
  check(schema.INTAKE_SCHEMA.find(section => section.id === 'medisch'));
  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  check(seed.sections.find(section => section.id === 'medisch'));
});

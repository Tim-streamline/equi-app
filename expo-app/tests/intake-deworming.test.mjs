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
const labels = [
  'Op vaste momenten, volgens een standaardschema',
  'Op basis van mestonderzoek',
  'Een combinatie van beide',
  'Mijn paard wordt niet ontwormd',
  'Weet ik niet',
];
const followups = ['ontworming-laatst', 'ontworming-middel', 'ontworming-volgende', 'ontworming-reactie', 'ontworming-volgende-detail', 'ontworming-reactie-toelichting'];

function check(section) {
  const byId = id => section.fields.find(field => field.id === id);
  assert.equal(byId('ontworming-methode').label, 'Hoe wordt bepaald óf en wanneer je paard ontworming nodig heeft?');
  assert.deepEqual(byId('ontworming-methode').options, labels);
  const stale = {
    'ontworming-laatst': '2026-01-01', 'ontworming-middel': 'middel',
    'ontworming-volgende': 'ja', 'ontworming-volgende-detail': 'volgende maand',
    'ontworming-reactie': ['Ja, anders, namelijk'], 'ontworming-reactie-toelichting': 'toelichting',
  };
  for (const method of labels) {
    const answers = { ...stale, 'ontworming-methode': method };
    const visible = logic.visibleFields(section, answers).map(field => field.id);
    for (const id of followups) assert.equal(visible.includes(id), labels.slice(0, 3).includes(method), `${method}: ${id}`);
  }
  const answers = { ...stale, 'ontworming-methode': labels[3], 'ontworming-laatst': '', 'ontworming-middel': '' };
  assert.equal(logic.missingRequired(section, answers).some(field => followups.includes(field.id)), false);
  answers['ontworming-methode'] = labels[0];
  assert.deepEqual(logic.missingRequired(section, answers).filter(field => followups.includes(field.id)).map(field => field.id), ['ontworming-laatst', 'ontworming-middel']);
  answers['ontworming-methode'] = labels[4];
  assert.equal(logic.missingRequired(section, answers).some(field => followups.includes(field.id)), false);
}

test('deworming branches in fallback and admin seed schemas', () => {
  check(schema.INTAKE_SCHEMA.find(section => section.id === 'medisch'));
  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  check(seed.sections.find(section => section.id === 'medisch'));
  assert.equal(logic.answersForSection(seed.sections.find(section => section.id === 'medisch'), { medisch: { 'ontworming-methode': 'Combinatie van beide' } })['ontworming-methode'], labels[2]);
});

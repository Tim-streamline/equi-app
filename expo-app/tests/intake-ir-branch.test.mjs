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
const confirmed = 'Ja, vastgesteld door dierenarts/onderzoek';
const research = 'Basale insulinemeting';
const limited = 'Beperkte hoeveelheid hooi gegeten';
const fasted = 'Helemaal niets gegeten (volledig gevast)';

function check(section) {
  const field = id => section.fields.find(item => item.id === id);
  const visible = answers => logic.visibleFields(section, answers).map(item => item.id);
  const missing = answers => logic.missingRequired(section, answers).map(item => item.id);
  assert.deepEqual(field('ir-onderzoeken').options, [
    'Basale insulinemeting', 'Orale suikertest (OST)', 'Orale glucosetest (OGT)',
    'Glucosemeting', 'Gecombineerd insuline- en glucoseonderzoek',
    'Intraveneuze glucosetolerantietest (IVGTT)', 'Gecombineerde glucose-insulinetest (CGIT)',
    'Ander onderzoek, namelijk...', 'Weet ik niet',
  ]);
  assert.equal(field('ir-onderzoek-wanneer-welk').type, 'month_year');
  assert.equal(field('ir-vermoeden-toelichting').label, 'Leg kort uit waarom je vermoedt dat je paard IR heeft.');
  assert.equal(field('ir-vermoeden-toelichting').type, 'textarea');
  assert.equal(field('ir-vermoeden-toelichting').lines, 2);
  assert.equal(field('ir-vermoeden-toelichting').placeholder, 'Beschrijf kort welke klachten, kenmerken of eerdere onderzoeken aanleiding geven tot je vermoeden.');
  assert.equal(field('ir-vermoeden-toelichting').signalConfig.condition, 'IR');
  assert.notEqual(field('ir-attest').optional, true);
  const base = { 'ir-status': confirmed };
  assert.ok(visible(base).includes('ir-onderzoeken'));
  assert.ok(!visible(base).includes('ir-voeding-vooraf'));
  assert.ok(!visible({ ...base, 'ir-onderzoeken': ['Weet ik niet'] }).includes('ir-voeding-vooraf'));
  const chosen = { ...base, 'ir-onderzoeken': [research] };
  assert.ok(visible(chosen).includes('ir-voeding-vooraf'));
  assert.ok(missing(chosen).includes('ir-voeding-vooraf'));
  assert.ok(visible({ ...base, 'ir-onderzoeken': ['Ander onderzoek, namelijk...'] }).includes('ir-onderzoeken-anders'));
  assert.ok(!visible({ ...base, 'ir-onderzoeken': [research] }).includes('ir-onderzoeken-anders'));
  assert.ok(visible({ ...chosen, 'ir-voeding-vooraf': limited }).includes('ir-hooi-hoeveelheid'));
  assert.ok(visible({ ...chosen, 'ir-voeding-vooraf': limited }).includes('ir-laatst-gegeten'));
  assert.ok(!visible({ ...chosen, 'ir-voeding-vooraf': limited }).includes('ir-vasten-duur'));
  assert.ok(logic.isFieldAnswered(field('ir-hooi-hoeveelheid'), 'Weet ik niet'));
  assert.ok(logic.isFieldAnswered(field('ir-hooi-hoeveelheid'), 2.5));
  assert.ok(!logic.isFieldAnswered(field('ir-hooi-hoeveelheid'), -1));
  assert.ok(visible({ ...chosen, 'ir-voeding-vooraf': fasted }).includes('ir-vasten-duur'));
  assert.ok(!visible({ ...chosen, 'ir-voeding-vooraf': fasted }).includes('ir-hooi-hoeveelheid'));
  assert.ok(visible({ ...base, 'ir-uitslag-beschikbaar': 'Ja' }).includes('ir-attest'));
  assert.ok(missing({ ...base, 'ir-uitslag-beschikbaar': 'Ja' }).includes('ir-attest'));
  assert.ok(!visible({ ...base, 'ir-uitslag-beschikbaar': 'Nee' }).includes('ir-attest'));
  assert.ok(!visible({ ...chosen, 'ir-voeding-vooraf': limited, 'ir-status': 'Voor zover ik weet niet' }).includes('ir-voeding-vooraf'));
  assert.ok(visible({ 'ir-status': 'Ik vermoed dit' }).includes('ir-vermoeden-toelichting'));
}

test('IR research, food, fasting and required result branches come from questionnaire data', () => {
  check(schema.INTAKE_SCHEMA.find(section => section.id === 'medisch'));
  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  check(seed.sections.find(section => section.id === 'medisch'));
});

test('synced admin edits are respected without runtime IR overrides', () => {
  const rows = [{ section_id: 'section-id', key: 'ir-onderzoeken', label: 'Adminonderzoek', type: 'multi',
    options: ['Aangepast'], show_if: { 'ir-status': 'Ja' }, active: true }];
  const section = config.buildIntakeSchema([{ id: 'section-id', key: 'medisch', title: 'Medisch', order: 5 }], rows)[0];
  assert.deepEqual(section.fields[0].options, ['Aangepast']);
  assert.equal(section.fields[0].label, 'Adminonderzoek');
  assert.equal(section.fields.length, 1);
});

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
const ids = ['kpu-onderzoek-datum', 'kpu-onderzoek-uitslag', 'kpu-uitslag-beschikbaar', 'kpu-attest', 'kpu-vermoeden-toelichting'];

function check(section) {
  const field = id => section.fields.find(item => item.id === id);
  const visible = answers => logic.visibleFields(section, answers).map(item => item.id);
  const missing = answers => logic.missingRequired(section, answers).map(item => item.id);
  assert.equal(field('kpu-onderzoek-datum').type, 'month_year');
  assert.match(field('kpu-onderzoek-datum').hint, /maand en het jaar/);
  assert.ok(logic.isFieldAnswered(field('kpu-onderzoek-datum'), 'voorjaar 2025'));
  assert.equal(field('kpu-onderzoek-uitslag').placeholder, 'Vul de gemeten waarde(n), eenheid en eventuele referentiewaarden in.');
  assert.notEqual(field('kpu-attest').optional, true);
  assert.equal(field('kpu-attest').hint, 'Upload minimaal één PDF of afbeelding.');
  assert.equal(field('kpu-vermoeden-toelichting').type, 'textarea');
  assert.equal(field('kpu-vermoeden-toelichting').lines, 2);
  assert.equal(field('kpu-vermoeden-toelichting').placeholder, 'Beschrijf kort welke klachten, kenmerken of eerdere onderzoeken aanleiding geven tot je vermoeden.');
  assert.equal(field('kpu-vermoeden-toelichting').signalConfig.condition, 'KPU');
  for (const status of ['Aangetoond met urineonderzoek', 'Ja, positieve uitslag bij urineonderzoek']) {
    const answers = { 'kpu-status': status };
    assert.deepEqual(ids.filter(id => visible(answers).includes(id)), ids.slice(0, 3));
    assert.deepEqual(ids.filter(id => missing(answers).includes(id)), ids.slice(0, 3));
    assert.ok(visible({ ...answers, 'kpu-uitslag-beschikbaar': 'Ja' }).includes('kpu-attest'));
    assert.ok(missing({ ...answers, 'kpu-uitslag-beschikbaar': 'Ja' }).includes('kpu-attest'));
    assert.ok(!visible({ ...answers, 'kpu-uitslag-beschikbaar': 'Nee' }).includes('kpu-attest'));
    assert.ok(!missing({ ...answers, 'kpu-onderzoek-datum': 'voorjaar 2025', 'kpu-onderzoek-uitslag': 'Waarde 10 eenheden' }).includes('kpu-onderzoek-datum'));
  }
  for (const status of ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf']) {
    const answers = { 'kpu-status': status, 'kpu-onderzoek-datum': 'voorjaar 2025', 'kpu-onderzoek-uitslag': 'Waarde 10' };
    assert.deepEqual(ids.filter(id => visible(answers).includes(id)), ['kpu-vermoeden-toelichting']);
    assert.deepEqual(ids.filter(id => missing(answers).includes(id)), ['kpu-vermoeden-toelichting']);
    assert.ok(!missing({ ...answers, 'kpu-vermoeden-toelichting': 'Op basis van de klachten' }).includes('kpu-vermoeden-toelichting'));
  }
  for (const status of ['Voor zover ik weet niet', 'Weet ik niet']) {
    assert.deepEqual(ids.filter(id => visible({ 'kpu-status': status, 'kpu-onderzoek-datum': 'voorjaar 2025' }).includes(id)), []);
  }
}

test('KPU asks availability before requiring an upload, with a separate owner-suspicion branch', () => {
  check(schema.INTAKE_SCHEMA.find(section => section.id === 'medisch'));
  const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  check(seed.sections.find(section => section.id === 'medisch'));
});

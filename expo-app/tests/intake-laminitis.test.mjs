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

const safety = load('../lib/intake/laminitis.ts');
const acute = safety.ACUTE[1], past = safety.PAST[1];
for (const [name, sections] of [['fallback', schema.INTAKE_SCHEMA], ['admin seed', JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8')).sections]]) {
 test(name + ': only applicable laminitis followups appear, without stale hidden blockers', () => {
  const section = sections.find(s => s.id === 'medisch');
  const fields = answers => logic.visibleFields(section, answers).map(f=>f.id);
  const missing = answers => logic.missingRequired(section, answers).map(f=>f.id);
  assert.ok(fields({hoefbevangenheid:acute}).includes('hoefbevangenheid-begonnen'));
  assert.ok(!fields({hoefbevangenheid:acute}).includes('hoefbevangenheid-recent'));
  assert.ok(fields({hoefbevangenheid:past,'hoefbevangenheid-hersteld':'Nee'}).includes('hoefbevangenheid-restklachten'));
  assert.ok(!fields({hoefbevangenheid:past,'hoefbevangenheid-hersteld':'Ja'}).includes('hoefbevangenheid-restklachten'));
  const risk = {hoefbevangenheid:'Mijn paard heeft een verhoogd risico op hoefbevangenheid','hoefbevangenheid-hersteld':'Nee'};
  assert.ok(fields(risk).includes('hoefbevangenheid-risico-toelichting'));
  assert.ok(!missing(risk).includes('hoefbevangenheid-restklachten'));
  const field=section.fields.find(f=>f.id==='hoefbevangenheid-risico-toelichting');assert.equal(field.type,'textarea');assert.equal(field.lines,2);
  assert.ok(fields({...risk,_laminitis_recovery_required:'Ja'}).includes(safety.RECOVERY_FIELDS[0]));
 });
}
test('a local acute hold survives choice changes until the matching server episode is explicitly released', () => {
 const state = {medisch:{hoefbevangenheid:'Nee, voor zover bekend niet',_laminitis_hold:'local-event'}};
 assert.equal(safety.laminitisHold(state,null),true);
 assert.equal(safety.laminitisHold(state,{episode_id:'server',client_marker:'local-event'}),true);
 assert.equal(safety.laminitisHold(state,{episode_id:'server',client_marker:'local-event',released_at:'today'}),false);
 assert.equal(safety.laminitisHold(state,{episode_id:'older',client_marker:'older',released_at:'today'}),true);
});

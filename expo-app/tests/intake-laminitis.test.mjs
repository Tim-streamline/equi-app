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
const settings = schema.INTAKE_SAFETY_CONFIG;
const acute = settings.triggers[0].when.hoefbevangenheid[1], past = settings.triggers[1].when.hoefbevangenheid[1];
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
  assert.ok(fields({...risk,_laminitis_recovery_required:'Ja'}).includes(Object.keys(settings.recovery.require)[0]));
 });
}
test('a local acute hold survives choice changes until the matching server episode is explicitly released', () => {
 const state = {medisch:{hoefbevangenheid:'Nee, voor zover bekend niet',_laminitis_hold:'local-event'}};
 assert.equal(safety.laminitisHold(state,null,settings),true);
 assert.equal(safety.laminitisHold(state,{episode_id:'server',client_marker:'local-event'},settings),true);
 assert.equal(safety.laminitisHold(state,{episode_id:'server',client_marker:'local-event',released_at:'today'},settings),false);
 assert.equal(safety.laminitisHold(state,{episode_id:'older',client_marker:'older',released_at:'today'},settings),true);
});

const custom = JSON.parse(readFileSync(new URL('../../backend/tests/Fixtures/intake-safety.json', import.meta.url), 'utf8'));
test('arbitrary section, field keys and answer values drive holds and recovery', () => {
 const answers = { 'custom-safety': { condition: 'urgent', resolved: 'Ja', stable: 'approved' } };
 assert.equal(safety.changedTrigger(custom, answers, 'custom-safety', 'condition').id, 'urgent');
 assert.equal(safety.changedTrigger(custom, answers, 'medisch', 'hoefbevangenheid'), undefined);
 assert.equal(safety.laminitisHold(answers, null, custom), true);
 const requirements = safety.recoveryRequirements(custom, answers);
 assert.equal(safety.matches(requirements, answers['custom-safety']), false);
 answers['custom-safety'].resolved = 'confirmed';
 assert.equal(safety.matches(requirements, answers['custom-safety']), true);
 assert.equal(safety.laminitisHold(answers, {episode_id:'one',released_at:'now',configuration:custom}, custom), false);
});
test('the full trigger must match: stale hidden recovery answers cannot create a local hold', () => {
 const answers = { 'custom-safety': { condition:'none', recovered:'not yet' } };
 assert.equal(safety.changedTrigger(custom, answers, 'custom-safety', 'recovered'), undefined);
 assert.equal(safety.laminitisHold(answers,null,custom), false);
 answers['custom-safety'].condition = 'previous';
 assert.equal(safety.changedTrigger(custom, answers, 'custom-safety', 'condition').id, 'ongoing');
 assert.equal(safety.laminitisHold(answers,null,custom), true);
});
test('pinned episodes retain conditional recovery after deleting answers and changing configuration', () => {
 const requirements = safety.recoveryRequirements(custom, {'custom-safety':{condition:'previous'}});
 const episode = {episode_id:'one',configuration:custom,requirements};
 assert.equal(safety.laminitisHold({},episode,null),true);
 assert.deepEqual(safety.recoveryRequirements(custom,{},episode), {resolved:['confirmed'],stable:['approved'],recovered:['recovered']});
 const answers = {'custom-safety':{_event:'offline-new'}};
 assert.equal(safety.laminitisHold(answers,{...episode,released_at:'now',client_marker:'older'},null),true);
});
test('aliases are configured read projections and do not mutate raw saved answers', () => {
 const section={id:'custom',fields:[{id:'choice',type:'radio',options:['current'],answerAliases:{legacy:'current'}}]};
 const answers={custom:{choice:'legacy'}};
 assert.deepEqual(logic.answersForSection(section,answers),{choice:'current'});
 assert.equal(answers.custom.choice,'legacy');
 const withoutAliases={...section,fields:[{...section.fields[0],answerAliases:undefined}]};
 assert.equal(logic.answersForSection(withoutAliases,answers).choice,'legacy');
});
test('reset preserves only pending safety markers, including markers from pinned settings', () => {
 const previous={...custom,section:'previous-section',marker_field:'_previous'};
 const answers={'custom-safety':{_event:'new',condition:'urgent'},'previous-section':{_previous:'older',other:'answer'},other:{field:'answer'}};
 assert.deepEqual(safety.safetyMarkers(answers,[custom,previous]),{'custom-safety':{_event:'new'},'previous-section':{_previous:'older'}});
 assert.equal(answers['custom-safety'].condition,'urgent');
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const modules = {};
for (const name of ['dates', 'schema', 'config', 'logic']) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(`../lib/intake/${name}.ts`, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', js)(key => modules[key], exports);
  modules[`./${name}`] = exports;
}
const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
const snapshot = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire-local.json', import.meta.url), 'utf8'));
const synced = modules['./config'].buildIntakeSchema(snapshot.sections.map(s => ({ ...s, id: s.key })),
  snapshot.sections.flatMap(s => s.fields.map(f => ({ ...f, section_id: s.key }))));
const followups = ['wegjagen-frequentie', 'wegjagen-gedrag', 'wegjagen-reactie', 'wegjagen-momenten'];
for (const [name, schema] of [['offline bootstrap', modules['./schema'].INTAKE_SCHEMA], ['admin configuration', seed.sections], ['synced configuration', synced]]) {
  const social = schema.find(s => s.id === 'sociaal'), housing = schema.find(s => s.id === 'huisvesting');
  const visible = answers => modules['./logic'].visibleFields(social, answers.sociaal ?? {}, answers).map(f => f.id);
  test(`${name}: housing progress excludes optional questions and agrees with completion`, () => {
    const logic = modules['./logic'];
    const values = { 'komt-op-weide': 'Nee, nooit', 'landbouw-nabij': 'Nee', 'giftige-planten': 'Nee', 'leefruimte-paarden': 1 };
    const answers = { huisvesting: values };
    // Fill each applicable required question, leaving every optional field empty.
    for (let pass = 0; pass < 3; pass++) {
      for (const f of logic.visibleFields(housing, values, answers)) {
        if (f.optional || values[f.id] !== undefined) continue;
        values[f.id] = f.type === 'multi' ? [f.options[0]] : f.type === 'number' ? 1 : f.options?.[0] ?? 'Ingevuld';
      }
    }
    assert.deepEqual(logic.missingRequired(housing, values, answers), []);
    const progress = logic.answeredCount(housing, values, answers);
    assert.equal(progress.answered, progress.total);
    assert.equal(logic.isSectionComplete(housing, values, answers), true);
    const optional = logic.visibleFields(housing, values, answers).filter(f => f.optional);
    assert.ok(optional.length >= 5);
    for (const f of optional) values[f.id] = f.type === 'photo' ? ['attachment:map:map.jpg'] : 'Extra toelichting';
    assert.deepEqual(logic.answeredCount(housing, values, answers), progress);
    delete values['leefruimte-oppervlakte'];
    assert.deepEqual(logic.answeredCount(housing, values, answers), { answered: progress.total - 1, total: progress.total });
    assert.equal(logic.isSectionComplete(housing, values, answers), false);
  });
  test(`${name}: old pasture answers do not keep dependent questions in progress after no pasture is selected`, () => {
    const logic = modules['./logic'];
    const values = { 'komt-op-weide': 'Nee, nooit', 'weide-omschrijving': 'Anders, namelijk', 'weide-bodem': ['Anders, namelijk'], 'weide-schuil': 'Ja, altijd' };
    const answers = { huisvesting: values };
    const followups = ['weide-omschrijving-anders', 'weide-bodem-anders', 'weide-schuil-soort'];
    const ids = () => logic.visibleFields(housing, values, answers).map(f => f.id);
    assert.ok(followups.every(id => !ids().includes(id)));
    const before = logic.answeredCount(housing, values, answers);
    for (const id of followups) values[id] = 'Bewaard antwoord';
    assert.deepEqual(logic.answeredCount(housing, values, answers), before);
    assert.ok(!logic.missingRequired(housing, values, answers).some(f => followups.includes(f.id)));
    values['komt-op-weide'] = 'Ja, het hele jaar door';
    assert.ok(followups.every(id => ids().includes(id)));
  });
  test(`${name}: one set of followups for chasing, interrupted meals and interrupted rest`, () => {
    for (const input of [
      { sociaal: { 'sociaal-spanning': ['Mijn paard wordt regelmatig weggejaagd / gepest'] } },
      { sociaal: { 'sociaal-positie': '…zelf wordt weggejaagd' } },
      { huisvesting: { 'ruwvoer-ongestoord': 'Meestal wel' } },
      { huisvesting: { 'rust-weggejaagd': 'Regelmatig' } },
    ]) {
      const answers = { ...input, huisvesting: { ...input.huisvesting, 'leefruimte-paarden': 3 } };
      for (const id of followups) assert.equal(visible(answers).filter(key => key === id).length, 1, id);
    }
  });
  test(`${name}: quiet groups and isolated horses do not get unnecessary questions; cached answers are retained`, () => {
    const old = { 'wegjagen-reactie': ['Blijft op afstand wachten'], 'wegjagen-reactie-anders': 'Een eerdere toelichting' };
    const answers = { huisvesting: { 'leefruimte-paarden': 3, 'ruwvoer-ongestoord': 'Ja, altijd' }, sociaal: { ...old, 'sociaal-spanning': ['Nauwelijks spanning of conflicten zichtbaar'], 'sociaal-positie': '…meestal rustig kan blijven staan zonder veel conflict' } };
    assert.ok(followups.every(id => !visible(answers).includes(id)));
    assert.ok(!visible(answers).includes('wegjagen-reactie-anders'));
    answers.sociaal['sociaal-spanning'] = ['Wegjagen bij voer'];
    assert.ok(followups.every(id => visible(answers).includes(id)));
    assert.ok(!visible(answers).includes('wegjagen-reactie-anders'));
    answers.sociaal['wegjagen-reactie'].push('Anders, namelijk...');
    assert.ok(visible(answers).includes('wegjagen-reactie-anders'));
    answers.sociaal['sociaal-leven'] = ['Individueel zonder zicht/neuscontact met andere paarden'];
    assert.ok(followups.every(id => !visible(answers).includes(id)));
    assert.equal(answers.sociaal['wegjagen-reactie-anders'], old['wegjagen-reactie-anders']);
  });
  test(`${name}: unsafe lying triggers shelter details; optional map uses the shared capped upload`, () => {
    const answers = { huisvesting: { 'buiten-droog-liggen': 'Nee' } };
    const fields = modules['./logic'].visibleFields(housing, answers.huisvesting, answers);
    assert.ok(fields.some(f => f.id === 'schuilplaats-inrichting'));
    const map = fields.find(f => f.id === 'leefomgeving-overzicht');
    assert.equal(map.type, 'photo'); assert.equal(map.photoConfig.max, 1);
    assert.equal(map.photoConfig.document_layout, 'full_page');
    assert.equal(modules['./logic'].isFieldRequired(map), false);
  });
}

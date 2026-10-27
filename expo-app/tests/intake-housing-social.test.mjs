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
const followups = ['wegjagen-frequentie', 'wegjagen-gedrag', 'wegjagen-reactie', 'wegjagen-momenten'];
for (const [name, schema] of [['offline bootstrap', modules['./schema'].INTAKE_SCHEMA], ['admin configuration', seed.sections]]) {
  const social = schema.find(s => s.id === 'sociaal'), housing = schema.find(s => s.id === 'huisvesting');
  const visible = answers => modules['./logic'].visibleFields(social, answers.sociaal ?? {}, answers).map(f => f.id);
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

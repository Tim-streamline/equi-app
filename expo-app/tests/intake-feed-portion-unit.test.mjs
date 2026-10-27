import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const modules = {};
for (const name of ['dates', 'schema', 'config', 'logic', 'repeater']) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(`../lib/intake/${name}.ts`, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', js)(key => modules[key], exports);
  modules[`./${name}`] = exports;
}
const { isFieldAnswered } = modules['./logic'];
const { repeaterValueText, repeaterUnitMissing, repeaterUnitColumns } = modules['./repeater'];
const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
const hint = 'Weet je het alleen in scheppen? Weeg één schep een keer af.';

for (const [name, schema] of [['fallback', modules['./schema'].INTAKE_SCHEMA], ['admin seed', seed.sections]]) {
  for (const key of ['huidige-bijvoeding', 'bijvoeding-historie']) {
    test(`${name}: ${key} asks the quantity with a gram or kilo choice`, () => {
      const field = schema.find(s => s.id === 'voer').fields.find(f => f.id === key);
      const amount = field.sub.find(s => s.id === 'hoeveelheid');
      const unit = field.sub.find(s => s.id === 'eenheid');
      assert.equal(amount.type, 'number');
      assert.equal(amount.unit, undefined);
      assert.equal(amount.unitField, 'eenheid');
      assert.equal(amount.hint, hint);
      assert.deepEqual(unit, { id: 'eenheid', label: 'Eenheid', type: 'radio', options: ['gram', 'kilo'] });
      assert.equal(field.sub.findIndex(s => s.id === 'eenheid'), field.sub.findIndex(s => s.id === 'hoeveelheid') + 1);

      assert.ok(!isFieldAnswered(field, [{ merk: 'A', hoeveelheid: '50' }]), 'a number without its unit is incomplete');
      assert.ok(!isFieldAnswered(field, [{ merk: 'A', hoeveelheid: '0,5', eenheid: '' }]));
      assert.ok(isFieldAnswered(field, [{ merk: 'A', hoeveelheid: '50', eenheid: 'gram' }]));
      assert.ok(isFieldAnswered(field, [{ merk: 'A', hoeveelheid: '2 scheppen' }]), 'legacy free text stays valid');
      assert.ok(isFieldAnswered(field, [{ merk: 'A' }]), 'the quantity itself stays optional');
    });
  }
}

test('quantities are displayed with the chosen unit and the unit column is folded in', () => {
  const amount = { id: 'hoeveelheid', type: 'number', unitField: 'eenheid' };
  assert.equal(repeaterValueText({ hoeveelheid: '50', eenheid: 'gram' }, amount), '50 gram');
  assert.equal(repeaterValueText({ hoeveelheid: '1,5', eenheid: 'kilo' }, amount), '1,5 kilo');
  assert.equal(repeaterValueText({ hoeveelheid: '2 scheppen' }, amount), '2 scheppen');
  assert.equal(repeaterValueText({ hoeveelheid: '50' }, amount), '50');
  assert.equal(repeaterValueText({ hoeveelheid: '2' }, { id: 'hoeveelheid', type: 'number', unit: 'kg' }), '2 kg');
  assert.ok(repeaterUnitMissing({ hoeveelheid: '50' }, amount));
  assert.ok(!repeaterUnitMissing({ hoeveelheid: '50', eenheid: 'kilo' }, amount));
  assert.ok(!repeaterUnitMissing({ hoeveelheid: '2 scheppen' }, amount));
  assert.deepEqual([...repeaterUnitColumns([amount, { id: 'eenheid', type: 'radio' }])], ['eenheid']);
});

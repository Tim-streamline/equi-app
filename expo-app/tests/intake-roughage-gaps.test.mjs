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
const main = 'hooi-zonder-ruwvoer-langste', detail = 'hooi-zonder-ruwvoer-toelichting', frequency = 'hooi-zonder-ruwvoer-frequentie';
const old = ['hooi-pauze-incl-nacht', 'hooi-pauze-uren', 'hooi-pauze-langste'];
const seed = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
for (const [name, schema] of [['fallback', modules['./schema'].INTAKE_SCHEMA], ['admin seed', seed.sections]]) {
  test(`${name}: roughage gaps use one question, with only applicable required followups`, () => {
    const section = schema.find(s => s.id === 'voer');
    const field = section.fields.find(f => f.id === main);
    assert.equal(field.type, 'radio');
    assert.equal(field.options.length, 7);
    assert.ok(old.every(key => !section.fields.some(f => f.id === key)));
    for (const option of field.options) {
      const answers = { [main]: option, [detail]: 'Een eerder antwoord', [frequency]: 'Dagelijks' };
      const visible = modules['./logic'].visibleFields(section, answers).map(f => f.id);
      assert.equal(visible.includes(detail), option === 'Wisselend');
      assert.equal(visible.includes(frequency), ![field.options[0], 'Weet ik niet', 'Wisselend'].includes(option));
      const missing = modules['./logic'].missingRequired(section, { [main]: option }).map(f => f.id);
      assert.equal(missing.includes(detail), option === 'Wisselend');
      assert.equal(missing.includes(frequency), ![field.options[0], 'Weet ik niet', 'Wisselend'].includes(option));
      assert.ok(!missing.includes(main));
    }
    assert.deepEqual(section.fields.filter(f => f.id.startsWith('hooi-zonder-ruwvoer')).map(f => f.id), [main, detail, frequency]);
  });
}

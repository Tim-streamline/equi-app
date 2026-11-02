import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { transformSync } from '@babel/core';

const require = createRequire(import.meta.url);
const modules = {};
for (const name of ['dates', 'schema', 'config', 'logic']) {
  const filename = fileURLToPath(new URL(`../../expo-app/lib/intake/${name}.ts`, import.meta.url));
  // TypeScript-only tests miss Expo's production transform of computed object rest.
  const { code } = transformSync(readFileSync(filename, 'utf8'), {
    filename,
    configFile: fileURLToPath(new URL('../babel.config.js', import.meta.url)),
    envName: 'production',
    caller: { name: 'metro', bundler: 'metro', platform: 'web', isDev: false, isServer: false, supportsStaticESM: false },
  });
  const exports = {};
  vm.runInNewContext(code, { exports, require: key => modules[key] ?? require(key) });
  modules[`./${name}`] = exports;
}

test('production intake evaluation terminates for hidden saved answers and preserves stored data', () => {
  const schema = [
    { id: 'early', fields: [{ id: 'detail', showIf: { 'original.parent': 'yes' } }] },
    { id: 'late', fields: [
      { id: 'enabled' },
      { id: 'parent', answerSection: 'original', showIf: { enabled: 'yes' } },
    ] },
  ];
  const answers = { early: { detail: 'saved' }, late: { enabled: 'no' }, original: { parent: 'yes', retained: 'keep' } };
  const original = structuredClone(answers);
  const context = { effectiveAnswers: modules['./logic'].effectiveAnswers, answers, schema };
  // A hard VM deadline catches the synchronous browser freeze without hanging the test runner.
  const result = vm.runInNewContext('effectiveAnswers(answers, schema, [])', context, { timeout: 250 });
  assert.deepEqual(JSON.parse(JSON.stringify(result)), { early: {}, late: { enabled: 'no' }, original: { retained: 'keep' } });
  assert.deepEqual(answers, original);
  assert.equal(context.effectiveAnswers(result, schema, []), result);
});

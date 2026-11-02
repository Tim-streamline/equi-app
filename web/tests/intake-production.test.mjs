import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const require = createRequire(new URL('../package.json', import.meta.url));
const root = fileURLToPath(new URL('../', import.meta.url));
const { transformer } = require('./metro.config.js');
const worker = require('expo/node_modules/@expo/metro-config/build/transform-worker/metro-transform-worker');

// Exercise the release transformer, not TypeScript's different emit pipeline.
// Run evaluation in a bounded VM so a convergence regression fails instead of
// hanging the test runner like it would the customer's browser.
async function releaseEvaluator(platform) {
  const modules = {};
  let factory;
  const context = vm.createContext({ __d: fn => { factory = fn; } });
  for (const name of ['dates', 'schema', 'config', 'logic', 'submission-blocks']) {
    const filename = `../expo-app/lib/intake/${name}.ts`;
    const built = await worker.transform(transformer, root, filename,
      readFileSync(new URL(filename, new URL('../', import.meta.url))), {
        dev: false, minify: true, platform, type: 'module',
        inlineRequires: false, experimentalImportSupport: false,
        customTransformOptions: { routerRoot: 'app', ...(platform === 'android' ? { engine: 'hermes' } : {}) },
      });
    vm.runInContext(built.output[0].data.code, context, { timeout: 1000 });
    const module = { exports: {} };
    factory(context, name => modules[name] ?? require(name), undefined, undefined,
      module, module.exports, built.dependencies.map(dependency => dependency.name));
    modules[`./${name}`] = module.exports;
  }
  context.logic = modules['./logic'];
  context.submission = modules['./submission-blocks'];
  return context;
}

for (const platform of ['web', 'android']) {
  test(`${platform} release removes hidden answers, converges and preserves stored answers`, async () => {
    const context = await releaseEvaluator(platform);
    const result = vm.runInContext(`(() => {
      const schema = [{ id: 'health', fields: [
        { id: 'child', type: 'text', answerSection: 'legacy', showIf: { parent: 'yes' } },
        { id: 'parent', type: 'radio', options: ['yes'], showIf: { gate: 'yes' },
          submissionBlocks: [{ answer: 'yes', message: 'Contact your vet.' }] },
        { id: 'gate', type: 'radio', options: ['yes', 'no'] },
      ] }];
      const answers = { health: { gate: 'no', parent: 'yes', unrelated: 'keep' }, legacy: { child: 'saved' } };
      const before = JSON.stringify(answers);
      const hidden = logic.effectiveAnswers(answers, schema, []);
      const blocks = submission.submissionBlocks(schema, answers, []);
      const visible = { ...answers, health: { ...answers.health, gate: 'yes' } };
      return JSON.stringify({ hidden, blocks, unchanged: before === JSON.stringify(answers),
        stable: logic.effectiveAnswers(hidden, schema, []) === hidden,
        visibleUnchanged: logic.effectiveAnswers(visible, schema, []) === visible,
        visibleBlocks: submission.submissionBlocks(schema, visible, []).map(block => block.message) });
    })()`, context, { timeout: 1000 });
    assert.deepEqual(JSON.parse(result), {
      hidden: { health: { gate: 'no', unrelated: 'keep' }, legacy: {} }, blocks: [],
      unchanged: true, stable: true, visibleUnchanged: true, visibleBlocks: ['Contact your vet.'],
    });
  });
}

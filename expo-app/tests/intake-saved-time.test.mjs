import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const code = ts.transpileModule(readFileSync(new URL('../lib/intake/store.tsx', import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText;
const exports = {};
new Function('require', 'exports', code)(name => name === 'react' ? { createContext: () => ({}) } : {}, exports);

test('saved labels interpret database timestamps as UTC in a non-UTC browser', () => {
  const previous = process.env.TZ;
  process.env.TZ = 'Europe/Budapest';
  try {
    const now = new Date('2026-10-12T23:24:10Z');
    for (const value of ['2026-10-12 23:24:00', '2026-10-12T23:24:00.000', '2026-10-12T23:24:00Z', '2026-10-13T01:24:00+02:00']) {
      assert.equal(exports.formatSavedAgo(value, now), 'Net opgeslagen');
    }
    assert.equal(exports.formatSavedAgo('2026-10-12 23:22:00', now), '2 min geleden');
    assert.equal(exports.formatSavedAgo(null, now), null);
    assert.equal(exports.formatSavedAgo('invalid', now), null);
  } finally {
    if (previous === undefined) delete process.env.TZ;
    else process.env.TZ = previous;
  }
});

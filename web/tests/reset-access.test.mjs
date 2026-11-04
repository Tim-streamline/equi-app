import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function setup() {
  const held = [], waiting = [];
  const drain = () => {
    for (const request of [...waiting]) {
      const conflict = held.some(other => other.name === request.name && (other.mode !== 'shared' || request.mode !== 'shared'));
      if (conflict && !request.ifAvailable) continue;
      waiting.splice(waiting.indexOf(request), 1);
      if (!conflict) held.push(request);
      Promise.resolve().then(() => request.work(conflict ? null : { name: request.name })).then(request.resolve, request.reject).finally(() => {
        if (!conflict) held.splice(held.indexOf(request), 1);
        drain();
      });
    }
  };
  const locks = { request: (name, options, work) => new Promise((resolve, reject) => {
    waiting.push({ name, mode: 'exclusive', ...options, work, resolve, reject }); drain();
  }) };
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL('../db/reset-access.ts', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
  }).outputText;
  new Function('exports', 'navigator', code)(exports, { locks });
  return exports.SyncResetAccess;
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('reset refuses account cleanup while another account tab is open', async () => {
  const Access = setup(), first = new Access(), second = new Access();
  await first.enter('owner'); await second.enter('owner');
  await assert.rejects(first.exclusive('owner', async () => assert.fail('must not clear')), /andere tabbladen/);
  await second.leave();
  let reset = false;
  await first.exclusive('owner', async () => { reset = true; });
  assert.equal(reset, true);
  await first.leave();
});

test('new tabs wait until reset finishes; another account is unaffected', async () => {
  const Access = setup(), first = new Access(), next = new Access(), other = new Access();
  await first.enter('owner'); await other.enter('other');
  let finish, entered = false;
  const resetting = first.exclusive('owner', () => new Promise(resolve => { finish = resolve; }));
  await settle();
  const entering = next.enter('owner').then(() => { entered = true; });
  await settle(); assert.equal(entered, false);
  finish(); await resetting; await entering;
  assert.equal(entered, true);
  await Promise.all([first.leave(), next.leave(), other.leave()]);
});

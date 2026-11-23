import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const exports = {};
const code = ts.transpileModule(readFileSync(new URL('../lib/protocol-order-ticks.ts', import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
}).outputText;
new Function('exports', code)(exports);

/** A fake server that applies requests in the order it receives them and answers each one when told to. */
function server() {
  let stored = [];
  const calls = [];
  const waiting = [];
  return {
    calls,
    get stored() { return stored; },
    send: (keys, ordered) => new Promise((resolve, reject) => {
      calls.push([keys, ordered]);
      waiting.push(() => {
        stored = ordered ? Array.from(new Set([...stored, ...keys])) : stored.filter(key => !keys.includes(key));
        resolve(stored);
      });
      waiting.at(-1).fail = () => reject(new Error('offline'));
    }),
    answer: () => waiting.shift()(),
    fail: () => waiting.shift().fail(),
  };
}
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

test('quick tick and untick are saved in tap order, so the server ends unticked', async () => {
  const fake = server();
  const ticks = exports.createOrderTicks(fake.send);
  const shown = [];
  ticks.toggle([], ['p:all'], true, (keys, failed) => shown.push([keys, failed]));
  ticks.toggle([], ['p:all'], false, (keys, failed) => shown.push([keys, failed]));
  assert.deepEqual(shown, [[['p:all'], false], [[], false]], 'taps show at once, even on stale input');
  await settle();
  assert.equal(fake.calls.length, 1, 'the second request waits for the first');
  fake.answer(); await settle();
  assert.equal(fake.calls.length, 2);
  fake.answer(); await settle();
  assert.deepEqual(fake.calls.map(call => call[1]), [true, false]);
  assert.deepEqual(fake.stored, []);
  assert.deepEqual(shown.at(-1), [[], false], 'the list ends on what the server confirmed');
});

test('the list only switches to the server answer once the last request is answered', async () => {
  const fake = server();
  const ticks = exports.createOrderTicks(fake.send);
  const shown = [];
  const update = (keys, failed) => shown.push([keys, failed]);
  ticks.toggle([], ['a:all'], true, update);
  ticks.toggle([], ['b:all'], true, update);
  await settle(); fake.answer(); await settle();
  assert.deepEqual(shown.at(-1), [['a:all', 'b:all'], false], 'a first answer does not undo the second tap');
  await settle(); fake.answer(); await settle();
  assert.deepEqual(shown.at(-1), [['a:all', 'b:all'], false]);
});

test('a tick that cannot be saved is undone and reported, and later taps still go through', async () => {
  const fake = server();
  const ticks = exports.createOrderTicks(fake.send);
  const shown = [];
  const update = (keys, failed) => shown.push([keys, failed]);
  ticks.toggle(['x:all'], ['a:all'], true, update);
  await settle(); fake.fail(); await settle();
  assert.deepEqual(shown.at(-1), [['x:all'], true]);
  ticks.toggle(['x:all'], ['b:all'], true, update);
  await settle(); fake.answer(); await settle();
  assert.deepEqual(shown.at(-1)[0], ['b:all']);
  assert.equal(shown.at(-1)[1], false, 'a new tap starts without the earlier error');
});

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
/** The order list's use of the queue: each tap ticks or unticks keys and sends just that change. */
const orderTicks = send => {
  const saves = exports.createSerialSave();
  return { toggle: (current, keys, ordered, onUpdate) => saves.save(current, exports.toggleKeys(keys, ordered), () => send(keys, ordered), onUpdate) };
};

test('quick tick and untick are saved in tap order, so the server ends unticked', async () => {
  const fake = server();
  const ticks = orderTicks(fake.send);
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
  const ticks = orderTicks(fake.send);
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
  const ticks = orderTicks(fake.send);
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

test('the last answer is marked as settled, also after a failure', async () => {
  const fake = server();
  const ticks = orderTicks(fake.send);
  const shown = [];
  ticks.toggle([], ['a:all'], true, (...args) => shown.push(args));
  ticks.toggle([], ['b:all'], true, (...args) => shown.push(args));
  await settle(); fake.answer(); await settle(); fake.fail(); await settle();
  assert.deepEqual(shown.map(update => update[2]), [false, false, true]);
  assert.deepEqual(shown.at(-1), [['a:all'], true, true], 'a failure settles on the last confirmed list');
});

test('another device undoing a tick shows once a dashboard requested after the last answer arrives', () => {
  const show = exports.shownValue;
  // This device ticked p:all; the server confirmed it after dashboard request 5 had started.
  const pending = { value: ['p:all'], settledAt: null };
  assert.deepEqual(show(pending, [], 9), ['p:all'], 'while saving the own taps stay, whatever polls return');
  const settled = { ...pending, settledAt: 5 };
  // The dashboard from before the tick still lists nothing, and a later one too after the other device unticked it:
  // equal key lists, so only the request number can tell them apart.
  assert.deepEqual(show(settled, [], 0), ['p:all'], 'a cached response does not decide');
  assert.deepEqual(show(settled, [], 5), ['p:all'], 'a poll already under way does not decide');
  assert.deepEqual(show(settled, [], 6), [], 'a dashboard requested afterwards does');
  assert.deepEqual(show(settled, ['p:all', 'q:all'], 6), ['p:all', 'q:all']);
  assert.deepEqual(show(null, ['x:all'], 0), ['x:all']);
});

test('dashboard responses carry their request number; cached and superseded polls never look newer', async t => {
  const compile = path => ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText;
  const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
  const state = [], refs = [], effects = [];
  let index = 0, refIndex = 0, started = false;
  const modules = {
    react: {
      useState: initial => { const i = index++; if (!(i in state)) state[i] = typeof initial === 'function' ? initial() : initial; return [state[i], next => { state[i] = typeof next === 'function' ? next(state[i]) : next; }]; },
      useRef: initial => refs[refIndex++] ??= { current: initial }, useCallback: fn => fn,
      useEffect: fn => { if (!started) effects.push(fn); },
    },
    'react-native': {}, 'expo-router': { useFocusEffect: () => {} },
    '@/lib/library-events': { onLibraryUnlock: () => () => {} },
    '@/lib/account-session': { accountSession: { revision: 0 } },
    '@react-native-async-storage/async-storage': { default: {
      getAllKeys: async () => [], multiRemove: async () => {}, removeItem: async () => {}, setItem: async () => {},
      getItem: async () => JSON.stringify({ orderedKeys: ['cached:all'] }),
    } },
    '@/db/provider': { useDb: () => ({ currentUserId: 'user', syncStatus: 'offline' }) },
    '@/db/hooks': { useCurrentHorseId: () => 'horse' }, '@/db/auth': { getApiBaseUrl: () => 'https://example.test' },
    '@/db/connector': { getOrMintToken: async () => 'token' },
    '@/lib/horse-dashboard': { dashboardAtTime: data => data, dashboardForHorse: data => data },
  };
  const answers = [];
  t.mock.method(globalThis, 'fetch', async () => new Promise(resolve => { answers.push(resolve); }));
  const hook = {};
  new Function('require', 'exports', compile('../hooks/useHorseDashboard.ts'))(name => modules[name], hook);
  const render = () => { index = refIndex = 0; return hook.useHorseDashboard(); };
  render(); effects.forEach(fn => fn()); started = true; await flush();
  assert.deepEqual([render().data.orderedKeys, render().revision], [['cached:all'], 0]);
  const before = hook.latestDashboardRequest();
  void render().refresh();
  const mark = hook.latestDashboardRequest();
  assert.equal(mark, before + 1);
  void render().refresh();
  await flush();
  // The newer poll answers first; the older one, answering later, is discarded.
  answers[1](new Response(JSON.stringify({ orderedKeys: [] })));
  await flush();
  answers[0](new Response(JSON.stringify({ orderedKeys: ['stale:all'] })));
  await flush();
  assert.deepEqual(render().data.orderedKeys, []);
  assert.equal(render().revision, mark + 1);
  assert.deepEqual(exports.shownValue({ value: ['mine:all'], settledAt: mark }, render().data.orderedKeys, render().revision), []);
});

test('a setting saves the whole value, so only the last request decides whether it failed', async () => {
  const sends = [];
  const send = value => () => new Promise((resolve, reject) => sends.push({ resolve: () => resolve(value), reject }));
  const run = async replaces => {
    sends.length = 0;
    const saves = exports.createSerialSave({ replaces });
    const shown = [];
    saves.save(false, () => true, send(true), (...args) => shown.push(args));
    saves.save(false, () => false, send(false), (...args) => shown.push(args));
    await settle(); sends[0].reject(new Error('offline'));
    await settle(); sends[1].resolve();
    await settle();
    return shown.at(-1);
  };
  assert.deepEqual(await run(true), [false, false, true], 'the later save stored the chosen value');
  assert.deepEqual(await run(false), [false, true, true], 'without replaces an earlier lost change is reported');
});

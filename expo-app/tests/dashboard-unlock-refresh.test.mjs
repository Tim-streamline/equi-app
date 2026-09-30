import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as events from '../lib/library-events.ts';
const compile = path => ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText;
const settle = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };

test('a mounted dashboard removes a purchased item immediately, replaces it from the API and retires old caches', async t => {
  const state = [], refs = [], effects = [], cleanups = [], removed = [];
  let index = 0, refIndex = 0, started = false, finish;
  const key = 'horse-dashboard:v3:user:horse:today';
  const cached = { recommendations: [{ id: 'bought' }, { id: 'keep' }] };
  const modules = {
    react: {
      useState: initial => { const i = index++; if (!(i in state)) state[i] = typeof initial === 'function' ? initial() : initial; return [state[i], next => { state[i] = typeof next === 'function' ? next(state[i]) : next; }]; },
      useRef: initial => refs[refIndex++] ??= { current: initial }, useCallback: fn => fn,
      useEffect: fn => { if (!started) effects.push(fn); },
    },
    'react-native': {}, 'expo-router': { useFocusEffect: () => {} },
    '@/lib/library-events': events,
    '@react-native-async-storage/async-storage': { default: {
      getAllKeys: async () => ['horse-dashboard:v1:old', 'horse-dashboard:v2:old', key, 'other'],
      multiRemove: async keys => removed.push(...keys), getItem: async () => JSON.stringify(cached),
      removeItem: async key => removed.push(key), setItem: async () => {},
    } },
    '@/db/provider': { useDb: () => ({ currentUserId: 'user', syncStatus: 'offline' }) },
    '@/db/hooks': { useCurrentHorseId: () => 'horse' }, '@/db/auth': { getApiBaseUrl: () => 'https://example.test' },
    '@/db/connector': { getOrMintToken: async () => 'token' },
    '@/lib/horse-dashboard': { dashboardAtTime: data => data, dashboardForHorse: data => data },
  };
  t.mock.method(globalThis, 'fetch', async () => new Promise(resolve => { finish = resolve; }));
  const exports = {};
  new Function('require', 'exports', compile('../hooks/useHorseDashboard.ts'))(name => modules[name], exports);
  const render = () => { index = refIndex = 0; return exports.useHorseDashboard(); };
  render(); effects.forEach(fn => cleanups.push(fn())); started = true; await settle();
  assert.equal(render().data.recommendations.length, 2);
  events.notifyLibraryUnlock('bought');
  assert.deepEqual(render().data.recommendations, [{ id: 'keep' }]);
  await settle();
  assert.ok(removed.includes(key));
  assert.ok(removed.includes('horse-dashboard:v2:old')); assert.ok(!removed.includes('other'));
  finish(new Response(JSON.stringify({ recommendations: [{ id: 'keep' }, { id: 'replacement' }] })));
  await settle();
  assert.deepEqual(render().data.recommendations, [{ id: 'keep' }, { id: 'replacement' }]);
  cleanups.forEach(fn => fn?.());
  events.notifyLibraryUnlock('keep');
  assert.equal(render().data.recommendations.length, 2, 'unmounted listeners are removed');
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import { dashboardForHorse } from '../lib/horse-dashboard.ts';

const code = ts.transpileModule(readFileSync(new URL('../hooks/useHorseDashboard.ts', import.meta.url), 'utf8'), {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
}).outputText;

test('dashboard loads account home without a horse and ignores its late response after horse creation', async t => {
  let horseId = '', userId = 'user', cursor = 0, refCursor = 0;
  const states = [], refs = [], requests = [], writes = [];
  const modules = {
    react: {
      useState: initial => { const i = cursor++; if (!(i in states)) states[i] = typeof initial === 'function' ? initial() : initial; return [states[i], value => { states[i] = typeof value === 'function' ? value(states[i]) : value; }]; },
      useRef: initial => refs[refCursor++] ??= { current: initial }, useCallback: fn => fn, useEffect: () => {},
    },
    'react-native': {}, 'expo-router': { useFocusEffect: () => {} },
    '@/lib/library-events': { onLibraryUnlock: () => () => {} },
    '@react-native-async-storage/async-storage': { default: { setItem: async (key, value) => writes.push([key, value]) } },
    '@/db/provider': { useDb: () => ({ currentUserId: userId, syncStatus: 'offline' }) },
    '@/db/hooks': { useCurrentHorseId: () => horseId },
    '@/db/auth': { getApiBaseUrl: () => 'https://example.test' },
    '@/db/connector': { getOrMintToken: async () => 'token' },
    '@/lib/horse-dashboard': { dashboardAtTime: data => data, dashboardForHorse },
  };
  t.mock.method(globalThis, 'fetch', async url => new Promise(resolve => requests.push({ url, resolve })));
  const exports = {};
  new Function('require', 'exports', code)(name => modules[name], exports);
  const render = () => { cursor = refCursor = 0; return exports.useHorseDashboard(); };
  const settle = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
  const home = { horse: { id: '', name: '' }, greeting: 'Goedemorgen, Shelley', protocol: null,
    recommendations: [1, 2, 3, 4].map(id => ({ id: String(id) })), variant: 'without-horse' };
  assert.equal(render().loading, true);
  const first = render().refresh(); await settle();
  assert.ok(requests[0].url.includes('/api/home?'));
  requests[0].resolve(new Response(JSON.stringify(home))); await first;
  assert.equal(render().data.greeting, 'Goedemorgen, Shelley');
  assert.equal(render().data.recommendations.length, 4);
  assert.equal(render().loading, false);
  assert.ok(writes[0][0].includes(':user::today'), 'Account home has a separate cache from horse dashboards');
  const late = render().refresh(); await settle();
  horseId = 'new-horse';
  assert.equal(render().data, null, 'No account snapshot appears under the new horse');
  const next = render().refresh(); await settle();
  assert.ok(requests[2].url.includes('/api/horses/new-horse/dashboard?'));
  requests[2].resolve(new Response(JSON.stringify({ ...home, horse: { id: horseId }, variant: 'basic' }))); await next;
  requests[1].resolve(new Response(JSON.stringify(home))); await late;
  assert.equal(render().data.variant, 'basic');
  userId = 'other-user';
  assert.equal(render().data, null, 'An account switch cannot expose the previous user’s dashboard');
});

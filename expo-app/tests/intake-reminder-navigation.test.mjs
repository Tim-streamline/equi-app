import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

test('a cold reminder link waits for the selected horse and intake provider before navigating', async () => {
  const target = '00000000-0000-4000-8000-000000000001';
  const user = '00000000-0000-4000-8000-000000000002';
  const booking = '00000000-0000-4000-8000-000000000003';
  let selectedHorseId = null, horseId = '', loaded = true, cursor = 0;
  const states = [], effects = [], routes = [];
  const modules = {
    react: {
      useState: initial => {
        const slot = cursor++;
        if (!(slot in states)) states[slot] = initial;
        return [states[slot], value => { states[slot] = value; }];
      },
      useEffect: effect => { effects.push(effect); },
    },
    'react/jsx-runtime': { jsx: () => null, jsxs: () => null },
    'react-native': { Text: 'Text', View: 'View' },
    'expo-router': { router: { replace: route => routes.push(route) }, useLocalSearchParams: () => ({ bookingId: booking, userId: user }) },
    '@/db/provider': { useDb: () => ({ currentUserId: user, selectedHorseId, selectHorse: id => { selectedHorseId = id; } }) },
    '@/lib/intake/store': { useIntake: () => ({ horseId, loaded }) },
    '@/hooks/useHorseDashboard': { dashboardRequest: async () => ({ horseId: target, stage: 'continue', section: 'photos', field: 'historie' }) },
    '@/components/ui/Button': { Button: 'Button' },
  };
  const code = ts.transpileModule(readFileSync(new URL('../app/intake/reminder.tsx', import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
  }).outputText;
  const exports = {};
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const render = async () => {
    cursor = 0;
    exports.default();
    effects.splice(0).forEach(effect => effect());
    await Promise.resolve();
  };
  await render(); // Resume response selects the horse before its synced rows arrive.
  assert.equal(selectedHorseId, target);
  await render();
  assert.deepEqual(routes, []);
  horseId = target;
  loaded = false;
  await render();
  assert.deepEqual(routes, []);
  loaded = true;
  await render();
  assert.deepEqual(routes, [{ pathname: '/intake/photos', params: { group: 'historie' } }]);
});

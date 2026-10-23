import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as common from '@powersync/common';
import * as syncOperations from '../lib/intake/sync-operations.ts';

function load(path, modules) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  new Function('require', 'exports', code)(key => { assert.ok(key in modules, key); return modules[key]; }, exports);
  return exports;
}
const identity = { response_id: 'booking', section_id: 'original', field_id: 'choice' };
const patch = { table: 'intake_answers', op: 'PATCH', id: 'answer', opData: { value: '"current"' } };

test('PowerSync tracks unchanged intake identity in every queued correction', () => {
  const { AppSchema } = load('../db/powersync-schema.ts', { '@powersync/react-native': common });
  const table = AppSchema.tables.find(table => table.name === 'intake_answers').toJSON();
  assert.deepEqual(table.include_old, ['response_id', 'section_id', 'field_id']);
  assert.equal(table.include_old_only_when_changed, false);
});

test('queued identity survives local deletion and never replaces the queued correction value', async () => {
  const op = { ...patch, opData: Object.freeze({ ...patch.opData }), previousValues: { ...identity, value: '"obsolete"' } };
  const result = await syncOperations.prepareSyncOperations({ getOptional: () => assert.fail('identity is already queued') }, [op]);
  assert.deepEqual(result, [{ op: 'PATCH', type: 'intake_answers', id: 'answer', data: { ...identity, value: '"current"' } }]);
  assert.deepEqual(op.opData, patch.opData);
});

test('pre-upgrade patches recover only identity from the local row, retaining transaction order and values', async () => {
  const operations = [patch, { ...patch, opData: { value: '"later"' } }, { table: 'intake_answers', op: 'DELETE', id: 'deleted' }];
  const result = await syncOperations.prepareSyncOperations({ getOptional: async (sql, params) => {
    assert.deepEqual(params, ['answer']);
    assert.match(sql, /SELECT response_id, section_id, field_id/);
    return { ...identity, value: '"latest local value"' };
  } }, operations);
  assert.deepEqual(result.map(op => op.data?.value), ['"current"', '"later"', undefined]);
  assert.deepEqual(result[0].data, { ...identity, value: '"current"' });
  assert.deepEqual(result[2], { op: 'DELETE', type: 'intake_answers', id: 'deleted', data: null });
});

for (const platform of ['native', 'web']) {
  for (const tracked of [true, false]) {
    test(`${platform}: ${tracked ? 'tracked' : 'legacy'} offline correction includes identity and is acknowledged only after success`, async t => {
      const session = { revision: 0 };
      const auth = { loadCredentials: async () => ({ userId: 'owner' }), getApiBaseUrl: () => 'https://api',
        login: async () => ({ token: 'token', expires_in: 3600 }), getSession: async () => ({ token: 'token', user: { id: 'owner' } }) };
      const { LaravelConnector } = load(platform === 'native' ? '../db/connector.ts' : '../../web/db/connector.ts', {
        './auth': auth, '@/lib/account-session': { accountSession: session }, '@/lib/intake/sync-operations': syncOperations,
        '@/lib/intake/attachments/sync': { uploadIntakeAttachments: async () => {} },
      });
      const previousWindow = globalThis.window;
      globalThis.window = { location: { origin: 'https://app' } };
      t.after(() => { globalThis.window = previousWindow; });
      let acknowledged = 0, status = 409, available = tracked;
      const op = tracked ? { ...patch, previousValues: identity } : patch;
      const db = { getNextCrudTransaction: async () => ({ crud: [op], complete: async () => { acknowledged++; } }),
        getOptional: async () => available ? identity : null };
      const requests = [];
      t.mock.method(globalThis, 'fetch', async (_url, options) => {
        requests.push(JSON.parse(options.body).operations[0]);
        return new Response('{}', { status });
      });
      const connector = new LaravelConnector('owner');
      await assert.rejects(connector.uploadData(db), /409/);
      assert.equal(acknowledged, 0, 'a rejected recovery must remain queued');
      if (!tracked) assert.deepEqual(requests[0].data, patch.opData);
      available = true; status = 200;
      await connector.uploadData(db);
      assert.deepEqual(requests[1], { op: 'PATCH', type: 'intake_answers', id: 'answer', data: { ...identity, value: '"current"' } });
      assert.equal(acknowledged, 1);
    });
  }
}

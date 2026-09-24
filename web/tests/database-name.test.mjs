import { test } from 'node:test';
import assert from 'node:assert/strict';
import { databaseName } from '../db/database-name.ts';
test('browser database names fit SQLite paths and isolate users and tabs', async () => {
  const first = await databaseName(crypto.randomUUID(), crypto.randomUUID());
  assert.ok(('/' + first + '-journal').length < 64);
  assert.equal(await databaseName('tab', 'user'), await databaseName('tab', 'user'));
  assert.notEqual(await databaseName('tab', 'user'), await databaseName('tab', 'other'));
  assert.notEqual(await databaseName('tab', 'user'), await databaseName('other', 'user'));
  assert.notEqual(await databaseName('tab', 'user'), await databaseName('tab', null));
});

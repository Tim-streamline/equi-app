import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ConnectionManager, SyncStatus } from '@powersync/common';
import { createLibrarySubscriptions } from '../../expo-app/lib/library-sync.ts';

const settle = () => new Promise(resolve => setImmediate(resolve));
const source = await readFile(new URL('../db/powersync.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

test('cached library grants added during SDK startup all reach the connection without changing grants or TTL', async () => {
  let releaseStartup;
  const startup = new Promise(resolve => { releaseStartup = resolve; });
  let wireSubscriptions = [];
  let initialSnapshot;
  const sync = {
    registerListener() { return () => {}; },
    async waitForReady() {},
    async connect() { wireSubscriptions = initialSnapshot; },
    updateSubscriptions(subscriptions) { wireSubscriptions = subscriptions; },
    async disconnect() {}, async dispose() {},
  };
  const commands = [];
  class BaseDatabase {
    constructor() {
      this.connectionManager = new ConnectionManager({
        logger: { debug() {}, warn() {} },
        createSyncImplementation: async (_, options) => {
          initialSnapshot = options.subscriptions;
          await startup;
          return { sync, onDispose() {} };
        },
      });
    }
    get syncStreamImplementation() { return this.connectionManager.syncStreamImplementation; }
    connect(...args) { return this.connectionManager.connect(...args); }
    syncStream(name, params) {
      return this.connectionManager.stream({
        async rustSubscriptionsCommand(command) { commands.push(command); },
        async resolveOfflineSyncStatus() {},
      }, name, params);
    }
  }
  const exports = {};
  new Function('require', 'exports', compiled)(name => {
    assert.equal(name, '@powersync/web/umd');
    return { PowerSyncDatabase: BaseDatabase };
  }, exports);
  const database = new exports.PowerSyncDatabase();
  const manager = createLibrarySubscriptions(database, error => { throw error; });
  manager.reconcile(['last']); await settle();
  const connecting = database.connect({}); await settle();
  manager.reconcile(['last', 'first', 'free-video', 'free-article', 'second', 'third']); await settle();
  assert.equal(initialSnapshot.length, 1, 'reproduce the SDK startup snapshot losing the later grants');
  releaseStartup(); await connecting;
  assert.deepEqual(wireSubscriptions.map(stream => stream.params.item_id).sort(), ['first', 'free-article', 'free-video', 'last', 'second', 'third']);
  assert.ok(commands.every(command => command.subscribe.ttl === 0));
  manager.reconcile(['first']);
  assert.deepEqual(wireSubscriptions.map(stream => stream.params.item_id), ['first'], 'revocation still removes the stream immediately');
  manager.dispose();
  assert.deepEqual(wireSubscriptions, []);
  await database.connectionManager.close();
});

test('a sync error without a value does not break the status listener', () => {
  // The SDK reads .name from every error except undefined; an IndexedDB failure rejected with null.
  const failed = () => new SyncStatus({ dataFlow: { uploadError: null, downloadError: null } });
  assert.throws(() => failed().toJSON(), TypeError, 'reproduce the SDK crash behind the failing retry button');
  class BaseDatabase { currentStatus = new SyncStatus({}); }
  const exports = {};
  new Function('require', 'exports', compiled)(() => ({ PowerSyncDatabase: BaseDatabase }), exports);
  const original = SyncStatus.prototype.serializeError;
  try {
    new exports.PowerSyncDatabase(); new exports.PowerSyncDatabase();
    assert.deepEqual(failed().toJSON().dataFlow, { uploadError: undefined, downloadError: undefined });
    const error = new Error('Uploaden is niet gelukt.');
    assert.equal(new SyncStatus({ dataFlow: { uploadError: error } }).toJSON().dataFlow.uploadError.message, error.message);
  } finally { SyncStatus.prototype.serializeError = original; }
});

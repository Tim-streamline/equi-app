import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function setup() {
  const instances = [];
  let finishInit, finishConnect;
  class Database {
    currentStatus = { connected: false, connecting: false };
    connections = 0;
    answers = new Map();
    constructor() { instances.push(this); }
    init() { return new Promise(resolve => { finishInit = resolve; }); }
    connect() {
      this.connections++;
      this.currentStatus.connecting = true;
      return new Promise(resolve => { finishConnect = () => { this.currentStatus = { connected: true, connecting: false }; resolve(); }; });
    }
    close() { assert.fail('a provider remount must not close the shared database'); }
  }
  const modules = {
    '@powersync/react-native': { PowerSyncDatabase: Database },
    '@powersync/op-sqlite': { OPSqliteOpenFactory: class {} },
    './powersync-schema': { AppSchema: {} },
    './connector': { LaravelConnector: class {} },
  };
  const exports = {};
  const source = readFileSync(new URL('../db/native-runtime.ts', import.meta.url), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', code)(name => modules[name], exports);
  return { ...exports, instances, ready: () => finishInit(), connected: () => finishConnect() };
}

test('provider remount during initialization shares one SQLite and sync client', async () => {
  const app = setup();
  const first = app.getNativeDatabase(), remount = app.getNativeDatabase();
  assert.equal(first, remount);
  assert.equal(app.instances.length, 1);
  app.ready();
  const db = await first;
  db.answers.set('offline-intake-answer', 'Ja');
  assert.equal(await app.getNativeDatabase(), db);
  assert.equal((await remount).answers.get('offline-intake-answer'), 'Ja');
});

test('login and a provider remount share the in-flight stream; logout permits reconnect', async () => {
  const app = setup();
  const loading = app.getNativeDatabase(); app.ready(); const db = await loading;
  const login = app.connectNativeDatabase(db);
  const remount = app.connectNativeDatabase(await app.getNativeDatabase());
  assert.equal(db.connections, 1);
  app.connected(); await Promise.all([login, remount]);
  await app.connectNativeDatabase(db);
  assert.equal(db.connections, 1, 'an already connected client must not restart');
  db.currentStatus = { connected: false, connecting: true };
  await app.connectNativeDatabase(db);
  assert.equal(db.connections, 1, 'leave automatic offline retries running');
  db.currentStatus = { connected: false, connecting: false };
  const relogin = app.connectNativeDatabase(db);
  assert.equal(db.connections, 2);
  app.connected(); await relogin;
});

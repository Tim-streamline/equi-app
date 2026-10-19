import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import { writeRelease } from '../scripts/write-release.mjs';
import { readWebRelease, refreshWebApp } from '../lib/web-release.ts';
import { RefreshSafety } from '../../expo-app/lib/refresh-safety-core.ts';
import { preserveIntakeForRefresh } from '../../expo-app/lib/intake/refresh.ts';
import { saveAnswer } from '../../expo-app/lib/intake/persistence.ts';
import { flushAttachmentUploads } from '../../expo-app/lib/intake/attachments/upload.ts';

test('each export stamps matching HTML and release metadata, including a second build', async t => {
  const directory = await mkdtemp(path.join(tmpdir(), 'equi-release-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  await writeFile(path.join(directory, 'index.html'), '<html><head><title>EquiApp</title></head><body>App</body></html>');
  for (const buildId of ['release-one', 'release-two']) {
    await writeRelease(directory, buildId);
    const html = await readFile(path.join(directory, 'index.html'), 'utf8');
    assert.equal(html.match(/name="equi-build"/g).length, 1);
    assert.ok(html.includes(`content="${buildId}"`));
    assert.deepEqual(JSON.parse(await readFile(path.join(directory, 'web-version.json'), 'utf8')), { buildId });
  }
});

test('version checks bypass caches and reject missing, malformed or offline responses', async () => {
  assert.equal(await readWebRelease(async (url, options) => {
    assert.equal(url, '/web-version.json'); assert.equal(options.cache, 'no-store');
    assert.ok(options.signal instanceof AbortSignal);
    return new Response(JSON.stringify({ buildId: 'new-release' }));
  }), 'new-release');
  for (const result of [new Response('', { status: 404 }), new Response('<html>fallback</html>'), new Response('{}')]) {
    await assert.rejects(readWebRelease(async () => result));
  }
  await assert.rejects(readWebRelease(async () => { throw new Error('offline'); }));
});

test('refresh waits for local persistence, but does not wait for network upload completion', async () => {
  let finish, reloaded = false;
  const safety = new RefreshSafety();
  safety.register({ prepare: () => new Promise(resolve => { finish = resolve; }) });
  const work = refreshWebApp({ verify: async () => 'next', prepare: () => safety.prepare(), reload: () => { reloaded = true; } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(reloaded, false);
  finish(); await work;
  assert.equal(reloaded, true);
});

test('unfinished photo selections and submissions block refresh until completed', async () => {
  const safety = new RefreshSafety();
  let pending = true, saved = false;
  safety.register({ reason: () => pending ? 'Assign selected photos first' : null, prepare: async () => { saved = true; } });
  await assert.rejects(safety.prepare(), /Assign selected photos/);
  assert.equal(saved, false);
  pending = false;
  await safety.prepare();
  assert.equal(saved, true);
});

test('storage errors, account changes and failed release checks never reload the page', async () => {
  const reload = () => assert.fail('must retain the current page');
  await assert.rejects(refreshWebApp({ prepare: async () => { throw new Error('disk full'); }, verify: async () => 'next', reload }), /disk full/);
  await assert.rejects(refreshWebApp({ prepare: async () => {}, verify: async () => { throw new Error('offline'); }, reload }), /offline/);
  const safety = new RefreshSafety();
  let unregister;
  unregister = safety.register({ prepare: async () => { unregister(); } });
  await assert.rejects(refreshWebApp({ prepare: () => safety.prepare(), verify: async () => 'next', reload }), /gewijzigd/);
});

test('latest answers and queued photo references survive closing and reopening the local database', async t => {
  const directory = await mkdtemp(path.join(tmpdir(), 'equi-refresh-db-'));
  t.after(() => rm(directory, { recursive: true, force: true }));
  const file = path.join(directory, 'answers.db');
  let db = new DatabaseSync(file);
  db.exec('CREATE TABLE intake_answers (id TEXT PRIMARY KEY,response_id TEXT,section_id TEXT,field_id TEXT,value TEXT,created_at TEXT,updated_at TEXT)');
  const adapter = {
    async getOptional(sql, params) { return db.prepare(sql).get(...params) ?? null; },
    async execute(sql, params) { return db.prepare(sql).run(...params); },
    async writeTransaction(work) { db.exec('BEGIN'); try { const result = await work(adapter); db.exec('COMMIT'); return result; } catch (error) { db.exec('ROLLBACK'); throw error; } },
  };
  const photoId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
  const answers = { fysiek: { 'foto-hoef-instructie': [`attachment:${photoId}:hoof.jpg`] }, paard: { naam: 'Latest edit' } };
  let drained = false, failed = true;
  const prepare = () => preserveIntakeForRefresh({
    drain: async () => { drained = true; }, checkSession() {}, answersToSave: () => answers, ensureBooking: async () => 'booking',
    save: async (...args) => { assert.ok(drained); if (failed) throw new Error('disk full'); return saveAnswer(adapter, ...args, () => crypto.randomUUID()); },
  });
  await assert.rejects(prepare(), /disk full/);
  failed = false;
  await prepare();
  db.close(); db = new DatabaseSync(file);
  assert.equal(JSON.parse(db.prepare("SELECT value FROM intake_answers WHERE field_id='naam'").get().value), 'Latest edit');
  const ops = db.prepare('SELECT value FROM intake_answers').all().map(opData => ({ table: 'intake_answers', opData }));
  // The persisted reference still drives an idempotent pending upload after reload.
  const blob = new Blob(['saved image bytes'], { type: 'image/jpeg' });
  let stored = { id: photoId, userId: 'owner', blob, uploaded: false };
  let uploads = 0;
  await flushAttachmentUploads(ops, 'owner', { get: async () => stored, put: async record => { stored = record; } }, async record => {
    assert.equal(await record.blob.text(), 'saved image bytes'); uploads++;
  }, () => {});
  assert.equal(uploads, 1); assert.equal(stored.uploaded, true);
  db.close();
});

test('a session switch during answer saving aborts preparation', async () => {
  let changed = false;
  await assert.rejects(preserveIntakeForRefresh({ drain: async () => {}, checkSession: () => { if (changed) throw new Error('session changed'); },
    answersToSave: () => ({ paard: { naam: 'Nova', leeftijd: 8 } }), ensureBooking: async () => 'booking',
    save: async () => { changed = true; },
  }), /session changed/);
});

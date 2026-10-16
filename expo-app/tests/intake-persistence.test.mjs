import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { ensureIntake, openOrCreateIntake, saveAnswer, bookingQuery } from '../lib/intake/persistence.ts';

function database() {
  const db = new DatabaseSync(':memory:');
  db.exec(`CREATE TABLE protocols (id TEXT PRIMARY KEY,status TEXT);
    CREATE TABLE intake_bookings (id TEXT PRIMARY KEY,protocol_id TEXT,user_id TEXT,horse_id TEXT,status TEXT,intake_status TEXT,started_at TEXT,submitted_at TEXT,completed_at TEXT,created_at TEXT,updated_at TEXT);
    CREATE TABLE intake_answers (id TEXT PRIMARY KEY,response_id TEXT,section_id TEXT,field_id TEXT,value TEXT,created_at TEXT,updated_at TEXT,UNIQUE(response_id,section_id,field_id));`);
  const adapter = {
    async getOptional(sql, args=[]) { return db.prepare(sql).get(...args) ?? null; },
    async execute(sql,args=[]) { return db.prepare(sql).run(...args); },
    async writeTransaction(work) { db.exec('BEGIN'); try { const result = await work(adapter); db.exec('COMMIT'); return result; } catch(e) { db.exec('ROLLBACK'); throw e; } },
  };
  const purchased = (id, user='u', horse='h', status='draft', submitted=null, created='2026-10-01') => {
    db.prepare('INSERT INTO protocols VALUES (?,?)').run(id,status);
    db.prepare("INSERT INTO intake_bookings (id,protocol_id,user_id,horse_id,status,submitted_at,created_at) VALUES (?,?,?,?,'pending',?,?)").run(id,id,user,horse,submitted,created);
  };
  return { db, adapter, purchased };
}
let n = 0;
const uuid = () => `uuid-${++n}`;
test('reopening and updating a purchased intake needs no network, including after reopening', async () => {
  const {db,adapter,purchased} = database(); purchased('existing');
  const offline = async () => { assert.fail('An existing intake must not request network authorization'); };
  for (const value of ['first offline edit', 'after reopening']) {
    const id = await openOrCreateIntake(adapter, 'u', 'h', offline);
    assert.equal(id, 'existing');
    await saveAnswer(adapter, id, 'paard', 'naam', value, uuid);
    assert.equal(JSON.parse(db.prepare('SELECT value FROM intake_answers').get().value), value);
  }
  assert.equal(db.prepare('SELECT count(*) n FROM intake_bookings').get().n, 1); db.close();
});
test('missing purchases never create a local intake, even if a paid intake is still syncing', async () => {
  const {db,adapter,purchased} = database(); purchased('foreign','someone-else');
  await assert.rejects(openOrCreateIntake(adapter,'u','h',async () => { throw new Error('offline'); }), /offline/);
  await assert.rejects(openOrCreateIntake(adapter,'u','h',async () => 'server-id'), /gesynchroniseerd/);
  assert.equal(db.prepare('SELECT count(*) n FROM intake_bookings').get().n, 1);
  purchased('server-id');
  assert.equal(await openOrCreateIntake(adapter,'u','h',async () => assert.fail()), 'server-id'); db.close();
});
test('purchased intakes are scoped by user AND horse', async () => {
  const {db,adapter,purchased}=database(); purchased('one','u','horse-one'); purchased('two','u','horse-two');
  assert.equal(await ensureIntake(adapter,'u','horse-one',()=> 'one'),'one');
  await assert.rejects(ensureIntake(adapter,'u','horse-two',()=> 'one'), /ander paard/);
  await assert.rejects(ensureIntake(adapter,'other-user','horse-one',()=> 'one'), /ander paard/);
  await assert.rejects(ensureIntake(adapter,'u','',uuid), /Selecteer/); db.close();
});
test('edits reuse a question row, preserve zero and structured answers, and delete cleared answers',async()=>{
  const {db,adapter,purchased}=database(); purchased('id'); const id='id';
  await saveAnswer(adapter,id,'paard','weight',540,uuid);
  await saveAnswer(adapter,id,'paard','weight',0,uuid);
  assert.equal(db.prepare('SELECT value FROM intake_answers').get().value,'0');
  assert.equal(db.prepare('SELECT count(*) n FROM intake_answers').get().n,1);
  const rows=[{name:'Hay',amount:'12'}];
  await saveAnswer(adapter,id,'voer','feed',rows,uuid);
  assert.deepEqual(JSON.parse(db.prepare("SELECT value FROM intake_answers WHERE field_id='feed'").get().value),rows);
  await saveAnswer(adapter,id,'paard','weight','',uuid);
  assert.equal(db.prepare('SELECT count(*) n FROM intake_answers').get().n,1); db.close();
});
test('finished history never overrides a newly purchased draft, and exact historical IDs remain scoped', async () => {
  const {db,adapter,purchased}=database();
  purchased('old','u','h','completed','2026-01-01','2026-01-01'); purchased('current');
  assert.equal((await adapter.getOptional(bookingQuery,['u','h'])).id, 'current');
  assert.equal(await ensureIntake(adapter,'u','h',()=> 'old'), 'old');
  assert.equal(await ensureIntake(adapter,'u','h',()=> 'current'), 'current');
  await assert.rejects(ensureIntake(adapter,'u','other',()=> 'old'), /ander paard/); db.close();
});

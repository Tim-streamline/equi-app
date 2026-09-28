import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { ensureIntake, saveAnswer, bookingQuery } from '../lib/intake/persistence.ts';

function database() {
  const db = new DatabaseSync(':memory:');
  db.exec(`CREATE TABLE intake_bookings (id TEXT PRIMARY KEY,user_id TEXT,horse_id TEXT,status TEXT,intake_status TEXT,started_at TEXT,submitted_at TEXT,created_at TEXT,updated_at TEXT);
    CREATE TABLE intake_answers (id TEXT PRIMARY KEY,response_id TEXT,section_id TEXT,field_id TEXT,value TEXT,created_at TEXT,updated_at TEXT,UNIQUE(response_id,section_id,field_id));`);
  const adapter = {
    async getOptional(sql, args=[]) { return db.prepare(sql).get(...args) ?? null; },
    async execute(sql,args=[]) { return db.prepare(sql).run(...args); },
    async writeTransaction(work) { db.exec('BEGIN'); try { const result = await work(adapter); db.exec('COMMIT'); return result; } catch(e) { db.exec('ROLLBACK'); throw e; } },
  };
  return { db, adapter };
}
let n = 0;
const uuid = () => `uuid-${++n}`;
test('existing booking becomes the intake, including unscheduled bookings, with no second record', async () => {
  const {db,adapter} = database();
  db.exec("INSERT INTO intake_bookings (id,user_id,horse_id,status,created_at) VALUES ('booked','u','h','confirmed','2026-09-01')");
  assert.equal(await ensureIntake(adapter,'u','h',uuid),'booked');
  await saveAnswer(adapter,'booked','paard','gewicht',540,uuid);
  assert.equal(db.prepare('SELECT count(*) as n FROM intake_bookings').get().n,1);
  assert.equal(db.prepare('SELECT response_id FROM intake_answers').get().response_id,'booked');
  assert.equal(db.prepare('SELECT status FROM intake_bookings').get().status,'confirmed');
  db.close();
});
test('intakes are scoped by user AND horse, cancelled appointments are not reused',async () => {
  const {db,adapter}=database();
  const first=await ensureIntake(adapter,'u','horse-one',uuid);
  assert.equal(first,await ensureIntake(adapter,'u','horse-one',uuid));
  const second=await ensureIntake(adapter,'u','horse-two',uuid);
  assert.notEqual(first,second);
  assert.notEqual(first,await ensureIntake(adapter,'other-user','horse-one',uuid));
  await adapter.execute("UPDATE intake_bookings SET status='cancelled' WHERE id=?",[first]);
  assert.notEqual(first,await ensureIntake(adapter,'u','horse-one',uuid));
  await assert.rejects(ensureIntake(adapter,'u','',uuid),/Selecteer/);
  db.close();
});
test('edits reuse a question row, preserve zero and structured answers, and delete cleared answers',async()=>{
  const {db,adapter}=database();
  const id=await ensureIntake(adapter,'u','h',uuid);
  await saveAnswer(adapter,id,'paard','weight',540,uuid);
  await saveAnswer(adapter,id,'paard','weight',0,uuid);
  assert.equal(db.prepare('SELECT value FROM intake_answers').get().value,'0');
  assert.equal(db.prepare('SELECT count(*) n FROM intake_answers').get().n,1);
  const rows=[{name:'Hay',amount:'12'}];
  await saveAnswer(adapter,id,'voer','feed',rows,uuid);
  assert.deepEqual(JSON.parse(db.prepare("SELECT value FROM intake_answers WHERE field_id='feed'").get().value),rows);
  await saveAnswer(adapter,id,'paard','weight','',uuid);
  assert.equal(db.prepare('SELECT count(*) n FROM intake_answers').get().n,1);
  assert.equal((await adapter.getOptional(bookingQuery,['u','h'])).id,id);
  db.close();
});

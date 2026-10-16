import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import ts from 'typescript';

test('purchased protocol query runs against the device schema and retains unfinished intakes', () => {
  const source = readFileSync(new URL('../db/powersync-schema.ts', import.meta.url), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  const exports = {};
  class Table { constructor(columns) { this.columns = columns; } }
  class Schema { constructor(tables) { this.tables = tables; } }
  new Function('require', 'exports', code)(() => ({ Table, Schema, column: { text: 'TEXT', integer: 'INTEGER', real: 'REAL' } }), exports);
  const schema = Object.values(exports).find(value => value instanceof Schema);
  const db = new DatabaseSync(':memory:');
  try {
    for (const name of ['protocols', 'intake_bookings']) {
      const columns = Object.entries(schema.tables[name].columns).map(([name, type]) => `"${name}" ${type}`);
      db.exec(`CREATE TABLE ${name} (id TEXT PRIMARY KEY, ${columns.join(', ')})`);
    }
    const insertProtocol = db.prepare('INSERT INTO protocols (id, horse_id, title, status, created_at) VALUES (?, ?, ?, ?, ?)');
    const insertIntake = db.prepare('INSERT INTO intake_bookings (id, protocol_id, user_id, horse_id, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?)');
    for (const [id, horse, user, status, intakeStatus, submitted, date] of [
      ['draft', 'rolo', 'owner', 'draft', 'pending', null, '2026-10-15'],
      ['finished', 'rolo', 'owner', 'completed', 'confirmed', '2026-10-01', '2026-10-01'],
      ['other-horse', 'nova', 'owner', 'draft', 'pending', null, '2026-10-16'],
      ['other-user', 'rolo', 'someone-else', 'draft', 'pending', null, '2026-10-16'],
      ['archived', 'rolo', 'owner', 'archived', 'pending', null, '2026-10-16'],
      ['cancelled', 'rolo', 'owner', 'draft', 'cancelled', null, '2026-10-16'],
    ]) {
      insertProtocol.run(id, horse, id, status, date);
      insertIntake.run(`intake-${id}`, id, user, horse, intakeStatus, submitted);
    }
    const component = readFileSync(new URL('../components/intake/PurchasedProtocols.tsx', import.meta.url), 'utf8');
    const sql = component.match(/`(\s*SELECT[\s\S]*?)`/)[1];
    const rows = db.prepare(sql).all('rolo', 'owner');
    assert.deepEqual(rows.map(row => row.id), ['draft', 'finished']);
    assert.equal(rows[0].booking_id, 'intake-draft');
    assert.equal(rows[0].submitted_at, null);
  } finally {
    db.close();
  }
});

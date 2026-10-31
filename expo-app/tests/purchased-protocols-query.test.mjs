import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import { PROTOCOLS_FOR_ALL_HORSES, PROTOCOLS_FOR_HORSE } from '../lib/intake/protocol-queries.ts';
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
    for (const name of ['protocols', 'intake_bookings', 'horses']) {
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
    const insertHorse = db.prepare('INSERT INTO horses (id, name, status) VALUES (?, ?, ?)');
    insertHorse.run('rolo', 'Rolo', 'active'); insertHorse.run('nova', 'Nova', 'active'); insertHorse.run('gone', 'Gone', 'archived');
    insertProtocol.run('gone-horse', 'gone', 'gone-horse', 'draft', '2026-10-17');
    insertIntake.run('intake-gone-horse', 'gone-horse', 'owner', 'gone', 'pending', null);
    const rows = db.prepare(PROTOCOLS_FOR_HORSE).all('rolo', 'owner');
    assert.deepEqual(rows.map(row => row.id), ['draft', 'finished']);
    assert.equal(rows[0].booking_id, 'intake-draft');
    assert.equal(rows[0].submitted_at, null);
    assert.equal(rows[0].horse_name, 'Rolo');
    // Home: every active horse of the user, each intake its own row.
    const all = db.prepare(PROTOCOLS_FOR_ALL_HORSES).all('owner');
    assert.deepEqual(all.map(row => [row.id, row.horse_id, row.horse_name]), [['other-horse', 'nova', 'Nova'], ['draft', 'rolo', 'Rolo'], ['finished', 'rolo', 'Rolo']]);
  } finally {
    db.close();
  }
});

test('both intake queries filter on an indexable column, without OR flags', () => {
  assert.match(PROTOCOLS_FOR_HORSE, /WHERE p\.horse_id = \?\s+AND i\.user_id = \?/);
  assert.match(PROTOCOLS_FOR_ALL_HORSES, /WHERE h\.status = 'active'\s+AND i\.user_id = \?/);
  for (const query of [PROTOCOLS_FOR_HORSE, PROTOCOLS_FOR_ALL_HORSES]) assert.doesNotMatch(query, /\bOR\b/);
});

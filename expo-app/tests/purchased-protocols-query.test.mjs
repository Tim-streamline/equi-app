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
    for (const name of ['protocols', 'intake_bookings', 'horses', 'intake_answers']) {
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
    // Only a real answer counts as having started: not photos, "not applicable" markers or empty values.
    const insertAnswer = db.prepare('INSERT INTO intake_answers (id, response_id, section_id, field_id, value) VALUES (?, ?, ?, ?, ?)');
    insertAnswer.run('a1', 'intake-draft', 'paard', 'naam', '"Rolo"');
    insertAnswer.run('a2', 'intake-finished', '__photos', 'foto', '"x"');
    insertAnswer.run('a3', 'intake-finished', 'paard', 'na:gewicht', '"yes"');
    insertAnswer.run('a4', 'intake-finished', 'paard', 'leeftijd', '""');
    insertAnswer.run('a5', 'intake-finished', 'paard', 'ras', '[]');
    const rows = db.prepare(PROTOCOLS_FOR_HORSE).all('rolo', 'owner');
    assert.deepEqual(rows.map(row => row.id), ['draft', 'finished']);
    assert.deepEqual(rows.map(row => row.has_answers), [1, 0]);
    assert.equal(rows[0].booking_id, 'intake-draft');
    assert.equal(rows[0].submitted_at, null);
    assert.equal(rows[0].horse_name, 'Rolo');
    // Home: every active horse of the user, each intake its own row.
    const all = db.prepare(PROTOCOLS_FOR_ALL_HORSES).all('owner');
    assert.deepEqual(all.map(row => [row.id, row.horse_id, row.horse_name]), [['other-horse', 'nova', 'Nova'], ['draft', 'rolo', 'Rolo'], ['finished', 'rolo', 'Rolo']]);
    db.prepare("UPDATE horses SET owner_id = 'owner' WHERE id = 'rolo'").run();
    insertProtocol.run('follow-up', 'rolo', 'Vervolgplan', 'paused', '2026-10-18');
    const followUp = user => db.prepare(PROTOCOLS_FOR_ALL_HORSES).all(user).find(row => row.id === 'follow-up');
    assert.equal(followUp('owner'), undefined, 'unpublished follow-ups stay hidden');
    db.prepare("UPDATE protocols SET published_at = '2026-01-01' WHERE id = 'follow-up'").run();
    assert.equal(followUp('owner').booking_id, null);
    assert.equal(followUp('owner').status, 'paused');
    assert.equal(followUp('other-owner'), undefined);
    db.prepare("UPDATE protocols SET status = 'active' WHERE id = 'follow-up'").run();
    assert.equal(followUp('owner'), undefined, 'after resuming, the normal dashboard card takes over');
  } finally {
    db.close();
  }
});

test('horse selection and all-horses queries have separate indexable filters', () => {
  assert.match(PROTOCOLS_FOR_HORSE, /WHERE p\.horse_id = \?\s+AND/);
  assert.match(PROTOCOLS_FOR_ALL_HORSES, /WHERE h\.status = 'active'\s+AND/);
});

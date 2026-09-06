import assert from 'node:assert/strict';
import test from 'node:test';
import { DatabaseSync } from 'node:sqlite';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { readHomePreferences, seasonalTipVisible, saveHomePreference } from '../lib/home-preferences.ts';

const tipA = { id: 'tip-a', month: 'augustus', title: 'Uit beheer', body: 'Introductie' };
const tipB = { ...tipA, id: 'tip-b' };

function executor(db) {
  return {
    async writeTransaction(fn) {
      db.exec('BEGIN');
      try {
        const result = await fn({
          async getOptional(sql, params) { return db.prepare(sql).get(...params) ?? null; },
          async execute(sql, params) { return db.prepare(sql).run(...params); },
        });
        db.exec('COMMIT');
        return result;
      } catch (error) { db.exec('ROLLBACK'); throw error; }
    },
  };
}
const read = (db, user) => readHomePreferences(db.prepare('SELECT * FROM user_home_preferences WHERE id = ?').get(user));

test('default is on, missing/old tips are not dismissible, and content identity is stable', () => {
  const prefs = readHomePreferences(null);
  assert.equal(prefs.seasonalTipsEnabled, true);
  assert.equal(seasonalTipVisible(tipA, prefs), true);
  assert.equal(seasonalTipVisible(null, prefs), false);
  assert.equal(seasonalTipVisible({ month: 'augustus' }, prefs), false);
  assert.equal(seasonalTipVisible({ ...tipA, title: 'Aangepaste titel' }, { ...prefs, dismissedTipIds: ['tip-a'] }), false);
});

test('dismissals persist across restarts and remain scoped to the user; new tips appear', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'equi-home-prefs-'));
  const path = join(dir, 'preferences.sqlite');
  let db = new DatabaseSync(path);
  try {
    db.exec('CREATE TABLE user_home_preferences (id TEXT PRIMARY KEY, seasonal_tips_enabled INTEGER, dismissed_tip_ids TEXT)');
    await saveHomePreference(executor(db), 'user-a', { type: 'dismiss', tipId: 'tip-a' });
    assert.equal(seasonalTipVisible(tipA, read(db, 'user-a')), false);
    assert.equal(seasonalTipVisible(tipB, read(db, 'user-a')), true);
    assert.equal(seasonalTipVisible(tipA, read(db, 'user-b')), true);
    db.close();
    db = new DatabaseSync(path);
    assert.equal(seasonalTipVisible(tipA, read(db, 'user-a')), false);
    await saveHomePreference(executor(db), 'user-a', { type: 'dismiss', tipId: 'tip-b' });
    await saveHomePreference(executor(db), 'user-a', { type: 'dismiss', tipId: 'tip-a' });
    assert.deepEqual(read(db, 'user-a').dismissedTipIds, ['tip-a', 'tip-b']);
    await saveHomePreference(executor(db), 'user-a', { type: 'set-enabled', enabled: false });
    assert.equal(seasonalTipVisible(tipB, read(db, 'user-a')), false);
    assert.equal(seasonalTipVisible({ id: 'new-tip' }, read(db, 'user-a')), false);
    db.close();
    db = new DatabaseSync(path);
    assert.equal(read(db, 'user-a').seasonalTipsEnabled, false);
    await saveHomePreference(executor(db), 'user-a', { type: 'set-enabled', enabled: true });
    assert.equal(seasonalTipVisible(tipB, read(db, 'user-a')), true);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM user_home_preferences').get().n, 1);
  } finally { db.close(); rmSync(dir, { recursive: true }); }
});

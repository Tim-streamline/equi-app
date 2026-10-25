import test from 'node:test';
import assert from 'node:assert/strict';
import { legacyScanScore, scanScoreLabel, recentScans, matchesFeed } from '../lib/scanner-presentation.ts';

test('missing or invalid legacy scores never appear as a zero score', () => {
  for (const value of [null, undefined, '', '  ', 'unknown', NaN, -1, 101, true]) {
    assert.equal(legacyScanScore(value), null);
    assert.equal(scanScoreLabel(value), '?');
  }
  assert.equal(legacyScanScore(0), 0);
  assert.equal(scanScoreLabel(0), '0');
  assert.equal(scanScoreLabel(62), '6,2');
  assert.equal(scanScoreLabel(100), '10');
});

test('recent scans use their stored dates without mutating the source', () => {
  const rows = [{ id: 'old', scannedAt: '2026-10-21T10:00:00Z' }, { id: 'unknown' }, { id: 'latest', scannedAt: '2026-10-25T10:00:00Z' }];
  assert.deepEqual(recentScans(rows).map(row => row.id), ['latest', 'old', 'unknown']);
  assert.equal(rows[0].id, 'old');
});

test('catalog search combines brand and product words, including legacy scan names', () => {
  assert.equal(matchesFeed({ id: 'a', brand: 'Metazoa', name: 'BalanceFit Timothee' }, ' timothee METAZOA '), true);
  assert.equal(matchesFeed({ id: 'a', brand: 'Metazoa', name: 'BalanceFit Timothee' }, 'timothee mash'), false);
  assert.equal(matchesFeed({ id: 'b', productName: 'NutriMash' }, 'nutri'), true);
});

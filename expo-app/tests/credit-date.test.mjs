import assert from 'node:assert/strict';
import test from 'node:test';
import { creditDate } from '../lib/credits.ts';
test('credit history displays PostgreSQL and ISO timestamps without Invalid Date', () => {
  for (const input of ['2026-09-26 12:00:00+00', '2026-09-26T12:00:00.000000Z', '2026-09-26 12:00:00', '2026-09-26']) {
    assert.equal(creditDate(input), '26 september 2026');
  }
  assert.equal(creditDate('invalid'), 'Datum onbekend');
});

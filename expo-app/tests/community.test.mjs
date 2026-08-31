import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, existsSync } from 'node:fs';
import { communityErrorMessage, communityQuery, contentActions, relativeTime } from '../lib/community.ts';

test('categories and saved posts use independent server filters', () => {
  assert.equal(communityQuery('', false, 1), '/api/community?page=1');
  assert.equal(communityQuery('food', true, 2), '/api/community?page=2&category_id=food&bookmarked=1');
});
test('ownership and participation control contextual actions', () => {
  assert.deepEqual(contentActions(true, true, false), ['edit', 'delete', 'share']);
  assert.deepEqual(contentActions(true, false, false), ['delete', 'share']);
  assert.deepEqual(contentActions(true, true, true), ['delete', 'share']);
  assert.deepEqual(contentActions(false, false, false), ['report', 'mute', 'share']);
});
test('timestamps are derived from the actual date instead of seeded labels', () => {
  const now = Date.parse('2026-08-30T12:00:00Z');
  assert.equal(relativeTime('2026-08-30T11:55:00Z', now), '5 min geleden');
  assert.equal(relativeTime('2026-08-29T12:00:00Z', now), 'gisteren');
});
test('hidden or deleted content shows a human message without internal model names', () => {
  assert.equal(communityErrorMessage(404, 'No query results for model [App\\Models\\CommunityPost]'), 'Dit bericht is niet meer beschikbaar.');
  assert.equal(communityErrorMessage(422, 'Maximaal vier bijlagen.'), 'Maximaal vier bijlagen.');
  assert.equal(communityErrorMessage(500, 'SQLSTATE secret details'), 'Community is tijdelijk niet bereikbaar. Probeer opnieuw.');
});
test('community replaces Account as the rightmost primary destination', () => {
  const layout = readFileSync(new URL('../app/(tabs)/(pager)/_layout.tsx', import.meta.url), 'utf8');
  assert.match(layout, /name="community"/);
  assert.doesNotMatch(layout, /name="account"/);
  assert.ok(existsSync(new URL('../app/(tabs)/account/index.tsx', import.meta.url)));
  const home = readFileSync(new URL('../app/(tabs)/(pager)/home.tsx', import.meta.url), 'utf8');
  assert.match(home, /router.push\("\/\(tabs\)\/account"\)/);
});

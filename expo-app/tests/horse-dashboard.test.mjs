import assert from 'node:assert/strict';
import test from 'node:test';
import { dashboardForHorse, libraryPath } from '../lib/horse-dashboard.ts';

test('never renders the previous horse dashboard after a horse switch', () => {
  const previous = { horse: { id: 'horse-a' }, protocol: { currentWeek: 4 } };
  assert.equal(dashboardForHorse(previous, 'horse-b'), null);
  assert.equal(dashboardForHorse(previous, 'horse-a'), previous);
  assert.equal(dashboardForHorse(null, 'horse-a'), null);
});

test('nutrition and home links open the actual library format', () => {
  assert.deepEqual(libraryPath({ id: 'hay-analysis', format: 'article' }), {
    pathname: '/(tabs)/library/article/[id]', params: { id: 'hay-analysis' },
  });
  assert.deepEqual(libraryPath({ id: 'water-analysis', format: 'video' }), {
    pathname: '/(tabs)/library/video/[id]', params: { id: 'water-analysis' },
  });
});

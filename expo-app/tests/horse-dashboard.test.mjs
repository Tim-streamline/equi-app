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

test('Home uses the same non-video destinations as Library for audio and courses', () => {
  for (const format of ['audio', 'podcast', 'course', 'program']) {
    assert.deepEqual(libraryPath({ id: 'lesson', format }), {
      pathname: '/(tabs)/library/article/[id]', params: { id: 'lesson' },
    });
  }
});

test('cached phase status follows exact availability and end boundaries without inventing offline content', async () => {
  const { dashboardAtTime } = await import('../lib/horse-dashboard.ts');
  const phase = { id: 'phase', weekStart: 3, weekEnd: 4, state: 'locked', accessible: false, contentAvailable: false,
    availableAt: '2026-03-29T00:00:00+01:00', startsAt: '2026-04-05T00:00:00+02:00', endsAt: '2026-04-19T00:00:00+02:00',
    description: null, supplements: [] };
  const data = { horse: { id: 'horse' }, protocol: { phases: [phase], orderItems: [], notifications: [] } };
  assert.equal(dashboardAtTime(data, new Date('2026-03-28T22:59:59Z')).protocol.phases[0].state, 'locked');
  const preview = dashboardAtTime(data, new Date('2026-03-28T23:00:00Z')).protocol.phases[0];
  assert.equal(preview.state, 'preview');
  assert.equal(preview.accessible, true);
  assert.equal(preview.contentAvailable, false);
  assert.deepEqual(preview.supplements, []);
  assert.equal(dashboardAtTime(data, new Date('2026-04-04T22:00:00Z')).protocol.phases[0].state, 'active');
  assert.equal(dashboardAtTime(data, new Date('2026-04-18T22:00:00Z')).protocol.phases[0].state, 'done');
});

test('cached cards calculate each overlapping phase week across daylight-saving changes', async () => {
  const { dashboardAtTime } = await import('../lib/horse-dashboard.ts');
  const phase = { id: 'one', weekStart: 1, weekEnd: 8, availableAt: '2026-03-15T00:00:00+01:00',
    startsAt: '2026-03-22T00:00:00+01:00', endsAt: '2026-05-17T00:00:00+02:00', supplements: [] };
  const second = { ...phase, id: 'two', weekStart: 2, weekEnd: 7,
    startsAt: '2026-03-29T00:00:00+01:00', endsAt: '2026-05-10T00:00:00+02:00' };
  const data = { timezone: 'Europe/Amsterdam', protocol: { phases: [phase, second], orderItems: [], notifications: [] } };
  const cards = dashboardAtTime(data, new Date('2026-04-04T22:00:00Z')).protocol.phases;
  assert.equal(cards[0].statusLabel, 'Actief · week 3 van 8');
  assert.equal(cards[0].durationLabel, 'Duur: 8 weken');
  assert.equal(cards[1].statusLabel, 'Actief · week 2 van 6');
  assert.equal(cards[1].durationLabel, 'Duur: 6 weken');
});

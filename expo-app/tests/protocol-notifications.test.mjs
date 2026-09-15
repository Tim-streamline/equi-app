import assert from 'node:assert/strict';
import test from 'node:test';
import { phaseNotificationRoute } from '../lib/protocol-notifications.ts';

test('phase push preserves exact account, horse, protocol and all grouped phase identities', () => {
  const data = { type: 'protocol_phase_preview', userId: 'user-1', horseId: 'horse-2', protocolId: 'protocol-3', phaseIds: ['phase-4', 'phase-5'] };
  assert.deepEqual(phaseNotificationRoute(data, 'user-1', 'notification-6'), {
    pathname: '/(tabs)/(pager)/protocol',
    params: { tab: 'kalender', horseId: 'horse-2', protocolId: 'protocol-3', phaseIds: 'phase-4,phase-5', t: 'notification-6' },
  });
  assert.equal(phaseNotificationRoute(data, 'different-account', 'id'), null);
  assert.equal(phaseNotificationRoute({ ...data, phaseIds: [] }, 'user-1', 'id'), null);
  assert.equal(phaseNotificationRoute({ ...data, horseId: '../other-screen' }, 'user-1', 'id'), null);
  assert.equal(phaseNotificationRoute({ ...data, type: 'external-url' }, 'user-1', 'id'), null);
});

test('a locked cached dashboard leaves the notification pending for delayed fresh availability', async () => {
  const { accessibleNotificationPhase } = await import('../lib/protocol-notifications.ts');
  const cached = { id: 'protocol', phases: [{ id: 'phase', accessible: false }] };
  assert.equal(accessibleNotificationPhase(cached, 'protocol', 'phase'), null);
  const fresh = { id: 'protocol', phases: [{ id: 'phase', accessible: true }] };
  assert.equal(accessibleNotificationPhase(fresh, 'protocol', 'phase'), fresh.phases[0]);
  assert.equal(accessibleNotificationPhase(fresh, 'old-protocol', 'phase'), null);
  assert.equal(accessibleNotificationPhase(fresh, 'protocol', 'deleted-phase'), null);
});

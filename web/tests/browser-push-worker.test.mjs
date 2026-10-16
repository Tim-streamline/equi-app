import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function worker() {
  const listeners = {}, shown = [], opened = [], closed = [];
  let binding = '';
  const context = {
    URL, Response,
    caches: { open: async () => ({ match: async () => new Response(binding), put: async (_, value) => { binding = await value.text(); } }) },
    self: {
      location: { origin: 'https://app.example.test' },
      addEventListener: (type, listener) => { listeners[type] = listener; },
      registration: { showNotification: async (title, options) => { shown.push({ title, ...options }); }, getNotifications: async () => shown.map(n => ({ ...n, close: () => closed.push(n) })) },
      clients: { matchAll: async () => [], openWindow: async url => { opened.push(url); } },
    },
  };
  vm.runInNewContext(readFileSync(new URL('../public/push-sw.js', import.meta.url), 'utf8'), context);
  const dispatch = async (type, event) => { let promise; listeners[type]({ ...event, waitUntil: p => { promise = p; } }); await promise; };
  return { shown, opened, closed, dispatch, bind: userId => dispatch('message', { data: { type: 'bind-user', userId }, ports: [{ postMessage() {} }] }) };
}
const userId = '11111111-1111-4111-8111-111111111111';
const otherUser = '22222222-2222-4222-8222-222222222222';
const bookingId = '33333333-3333-4333-8333-333333333333';
const payload = { title: 'Intake', body: 'Ga verder', data: { type: 'intake_reminder', userId, bookingId, url: 'https://untrusted.example/' } };

test('worker drops malformed pushes and pushes for a signed-out or different account', async () => {
  const w = worker();
  await w.dispatch('push', { data: { json: () => payload } });
  await w.bind(otherUser);
  await w.dispatch('push', { data: { json: () => payload } });
  await w.dispatch('push', { data: { json: () => { throw Error('bad JSON'); } } });
  assert.equal(w.shown.length, 0);
});
test('valid push opens only the same-origin booking route and account changes dismiss old notifications', async () => {
  const w = worker(); await w.bind(userId);
  await w.dispatch('push', { data: { json: () => payload } });
  assert.equal(w.shown.length, 1);
  assert.equal(w.shown[0].tag, `intake_reminder-${bookingId}`);
  const click = { notification: { data: payload.data, close() {} } };
  await w.dispatch('notificationclick', click);
  assert.equal(w.opened[0], `https://app.example.test/intake/reminder?bookingId=${bookingId}&userId=${userId}`);
  await w.bind(null);
  assert.equal(w.closed.length, 1);
  await w.dispatch('notificationclick', click);
  assert.equal(w.opened.length, 1);
});

test('protocol and credit pushes route to their own account screens', async () => {
  const w = worker(); await w.bind(userId);
  const data = { type: 'protocol_phase_preview', userId, horseId: bookingId, protocolId: otherUser, phaseIds: [bookingId] };
  await w.dispatch('push', { data: { json: () => ({ ...payload, data }) } });
  await w.dispatch('notificationclick', { notification: { data, close() {} } });
  const url = new URL(w.opened[0]);
  assert.equal(url.pathname, '/protocol'); assert.equal(url.searchParams.get('phaseIds'), bookingId);
  assert.equal(url.searchParams.get('horseId'), bookingId);
  await w.dispatch('notificationclick', { notification: { data: { type: 'credit_expiry', userId }, close() {} } });
  assert.equal(w.opened[1], 'https://app.example.test/account/credits');
  assert.equal(w.shown.length, 1);
});

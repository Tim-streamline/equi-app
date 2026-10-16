/* Notification-only worker: it never caches application pages or authenticated responses. */
const bindingCache = 'equi-push-binding-v1';
const bindingUrl = new URL('/__push_user', self.location.origin).href;
const readUser = async () => (await (await caches.open(bindingCache)).match(bindingUrl))?.text();
const identifier = value => typeof value === 'string' && /^[a-f0-9-]{36}$/i.test(value);
function route(data) {
  if (!identifier(data?.userId)) return null;
  const url = new URL(self.location.origin);
  if (data.type === 'intake_reminder' && identifier(data.bookingId)) {
    url.pathname = '/intake/reminder'; url.searchParams.set('bookingId', data.bookingId); url.searchParams.set('userId', data.userId);
  } else if (data.type === 'protocol_phase_preview' && identifier(data.horseId) && identifier(data.protocolId) && Array.isArray(data.phaseIds) && data.phaseIds.length && data.phaseIds.every(identifier)) {
    url.pathname = '/protocol';
    for (const [key, value] of Object.entries({ tab: 'kalender', horseId: data.horseId, protocolId: data.protocolId, phaseIds: data.phaseIds.join(','), t: String(Date.now()) })) url.searchParams.set(key, value);
  } else if (data.type === 'credit_expiry') url.pathname = '/account/credits';
  else return null;
  return url;
}
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('message', event => {
  if (event.data?.type !== 'bind-user') return;
  event.waitUntil((async () => {
    const userId = typeof event.data.userId === 'string' ? event.data.userId : '';
    await (await caches.open(bindingCache)).put(bindingUrl, new Response(userId));
    const notifications = await self.registration.getNotifications();
    for (const notification of notifications) if (notification.data?.userId !== userId) notification.close();
    event.ports[0]?.postMessage({ saved: true });
  })());
});
self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let payload;
    try { payload = event.data?.json(); } catch { return; }
    const data = payload?.data;
    if (!route(data) || data.userId !== await readUser()) return;
    await self.registration.showNotification(payload.title, { body: payload.body, tag: `${data.type}-${data.bookingId ?? data.protocolId ?? data.userId}`, data });
  })());
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const data = event.notification.data;
    if (data?.userId !== await readUser()) return;
    const url = route(data);
    if (!url) return;
    for (const client of await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) {
      if (new URL(client.url).origin === self.location.origin) { await client.navigate(url.href); return client.focus(); }
    }
    return self.clients.openWindow(url.href);
  })());
});

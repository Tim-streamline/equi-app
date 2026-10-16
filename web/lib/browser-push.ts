import { accountSession } from '@/lib/account-session';
import { dashboardRequest } from '@/hooks/useHorseDashboard';

// Notifications are enabled by default; keep an explicit choice to turn them off.
export const browserPushOptedIn = (userId: string) => localStorage.getItem(`equi-push-opt:${userId}`) !== 'no';
export const browserPushSupported = () => typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
async function worker() { return navigator.serviceWorker.register('/push-sw.js', { scope: '/' }); }
let enrollmentRevision = 0;
let pushOperations: Promise<unknown> = Promise.resolve();
function serializePush<T>(action: () => Promise<T>): Promise<T> {
  const result = pushOperations.then(action);
  pushOperations = result.catch(() => undefined);
  return result;
}
export function bindPushUser(userId: string | null) {
  return serializePush(() => writeBinding(userId));
}
async function writeBinding(userId: string | null, isCurrent = () => true) {
  if (!browserPushSupported()) return;
  await worker();
  const ready = await navigator.serviceWorker.ready;
  if (!isCurrent()) return;
  await new Promise<void>((resolve, reject) => {
    const channel = new MessageChannel();
    const timeout = setTimeout(() => reject(new Error('Meldingen konden niet worden ingesteld.')), 5000);
    channel.port1.onmessage = () => { clearTimeout(timeout); channel.port1.close(); resolve(); };
    ready.active?.postMessage({ type: 'bind-user', userId }, [channel.port2]);
  });
}
function keyBytes(value: string): Uint8Array<ArrayBuffer> {
  const raw = atob(value.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, c => c.charCodeAt(0));
}
export async function enableBrowserPush(userId: string, requestPermission = true) {
  const revision = accountSession.revision;
  const enrollment = requestPermission ? ++enrollmentRevision : enrollmentRevision;
  const sameIntent = () => enrollment === enrollmentRevision && revision === accountSession.revision;
  const isCurrent = () => sameIntent() && browserPushOptedIn(userId);
  if (!browserPushSupported()) throw new Error('Pushmeldingen zijn niet beschikbaar in deze browser. Gebruik HTTPS; op iPhone/iPad voeg je de webapp eerst toe aan het beginscherm.');
  if (!requestPermission && !isCurrent()) return;
  // Request permission directly from the user's button click, before any fetch.
  const permission = requestPermission ? await Notification.requestPermission() : Notification.permission;
  if (!sameIntent()) return;
  if (permission !== 'granted') throw new Error('Sta meldingen toe in de instellingen van je browser.');
  // Only an explicit enable action changes the preference. A background resume
  // must never overwrite an opt-out when a delayed request finishes.
  if (requestPermission) localStorage.setItem(`equi-push-opt:${userId}`, 'yes');
  if (!isCurrent()) return;
  const config = await dashboardRequest('/api/notifications/web-push');
  if (!isCurrent()) return;
  if (!config.publicKey) throw new Error('Browsermeldingen zijn nog niet ingesteld op de server.');
  // Take the account lock before the push queue: logout already holds that
  // lock when it calls disableBrowserPush, so the reverse order deadlocks.
  await accountSession.forSession(revision, () => serializePush(async () => {
    if (!isCurrent()) return;
    await worker();
    if (!isCurrent()) return;
    const registration = await navigator.serviceWorker.ready;
    if (!isCurrent()) return;
    let subscription = await registration.pushManager.getSubscription();
    if (!isCurrent()) return;
    if (subscription && String(subscription.options.applicationServerKey && Array.from(new Uint8Array(subscription.options.applicationServerKey))) !== String(Array.from(keyBytes(config.publicKey)))) {
      await subscription.unsubscribe(); subscription = null;
    }
    if (!isCurrent()) return;
    subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(config.publicKey) });
    if (!isCurrent()) return;
    await dashboardRequest('/api/notifications/web-push', { ...subscription.toJSON(), timezone: Intl.DateTimeFormat().resolvedOptions().timeZone });
    if (!isCurrent()) return;
    await writeBinding(userId, isCurrent);
  }));
}
export async function disableBrowserPush(userId?: string) {
  // Invalidate immediately, including while permission/configuration is pending.
  enrollmentRevision++;
  if (userId) localStorage.setItem(`equi-push-opt:${userId}`, 'no');
  if (!browserPushSupported()) return;
  await serializePush(async () => {
    try { await writeBinding(null); }
    finally {
      const registration = await navigator.serviceWorker.getRegistration('/');
      const subscription = await registration?.pushManager.getSubscription();
      if (subscription) {
        // Run after any in-flight registration, even if binding/removal fails.
        try { await dashboardRequest('/api/notifications/web-push/remove', { endpoint: subscription.endpoint }); }
        finally { await subscription.unsubscribe(); }
      }
    }
  });
}

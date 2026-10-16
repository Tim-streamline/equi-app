import type { AbstractPowerSyncDatabase, PowerSyncBackendConnector, PowerSyncCredentials } from '@powersync/common';
import { accountSession } from '../lib/account-session';

// The installed PowerSync core records sync timestamps in whole seconds.
// Reconnecting within the previous checkpoint's second can complete successfully
// with the SAME timestamp, leaving a strict freshness check waiting forever.
async function waitForNextSyncSecond(lastSyncedAt: number, signal: AbortSignal): Promise<void> {
  const delay = lastSyncedAt + 1000 - Date.now();
  if (delay <= 0 || signal.aborted) return;
  await new Promise<void>(resolve => {
    const finish = () => {
      clearTimeout(timer);
      signal.removeEventListener('abort', finish);
      resolve();
    };
    const timer = setTimeout(finish, delay);
    signal.addEventListener('abort', finish, { once: true });
  });
}

/** Re-authorize sync without deleting cached rows or pending offline writes. */
export async function refreshSubscriptionConnection(
  database: Pick<AbstractPowerSyncDatabase, 'disconnect' | 'connect' | 'currentStatus' | 'waitForStatus'>,
  connector: PowerSyncBackendConnector,
  fetchFreshCredentials: (signal: AbortSignal) => Promise<PowerSyncCredentials | null>,
  signal: AbortSignal,
): Promise<void> {
  const revision = accountSession.revision;
  const controller = new AbortController();
  const abort = () => controller.abort();
  signal.addEventListener('abort', abort);
  if (signal.aborted) abort();
  const timeout = setTimeout(abort, 30_000);
  const checkSession = () => {
    if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
  };
  const check = () => {
    checkSession();
    if (controller.signal.aborted) throw new Error('Je gegevens konden niet worden geladen. Probeer opnieuw.');
  };
  let cancelFetch: (() => void) | undefined;
  try {
    check();
    // Do network work outside the account queue so logout is never held up by it.
    let credentials = await Promise.race([
      fetchFreshCredentials(controller.signal),
      new Promise<never>((_, reject) => {
        cancelFetch = () => reject(new Error('Je gegevens konden niet worden geladen. Probeer opnieuw.'));
        controller.signal.addEventListener('abort', cancelFetch);
        if (controller.signal.aborted) cancelFetch();
      }),
    ]);
    check();
    if (!credentials) throw new Error('Meld je opnieuw aan.');
    let lastSyncedAt = 0;
    await accountSession.forSession(revision, async () => {
      check();
      await database.disconnect();
      checkSession();
      lastSyncedAt = database.currentStatus.lastSyncedAt?.getTime() ?? 0;
    });
    // Wait outside the account queue so logout can still proceed immediately.
    // Usually no wait is needed; activation immediately after a sync needs up to
    // one second before the next checkpoint can have a distinguishable timestamp.
    await waitForNextSyncSecond(lastSyncedAt, controller.signal);
    check();
    await accountSession.forSession(revision, async () => {
      check();
      // Supply the freshly minted token to the new stream, not a cached token
      // or a second request that can race with activation/account changes.
      await database.connect({
        fetchCredentials: async () => {
          checkSession();
          const fresh = credentials;
          credentials = null;
          const result = fresh ?? await connector.fetchCredentials();
          checkSession();
          return result;
        },
        uploadData: db => { checkSession(); return connector.uploadData(db); },
      });
    });
    check();
    // hasSynced includes earlier sessions. Require a checkpoint from this one.
    await database.waitForStatus(status => status.connected && (status.lastSyncedAt?.getTime() ?? 0) > lastSyncedAt, controller.signal);
    check(); // PowerSync resolves waitForStatus on abort as well as success.
  } finally {
    clearTimeout(timeout);
    if (cancelFetch) controller.signal.removeEventListener('abort', cancelFetch);
    controller.abort();
    signal.removeEventListener('abort', abort);
  }
}

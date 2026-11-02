import type { AbstractPowerSyncDatabase, PowerSyncBackendConnector } from '@powersync/common';
import { accountSession } from '../lib/account-session';
import { cancelSyncRequests, syncRequest } from '../lib/sync-request';
import { reportSyncFailure } from '../lib/sync-errors';

const pending = new WeakMap<object, Promise<void>>();

/** Restart the uploader, preserving the database, pending CRUD and attachment bytes. */
export function retrySyncUploads(database: Pick<AbstractPowerSyncDatabase, 'disconnect' | 'connect'>, connector: PowerSyncBackendConnector): Promise<void> {
  const existing = pending.get(database);
  if (existing) return existing;
  const revision = accountSession.revision;
  const checkSession = () => {
    if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
  };
  const work = syncRequest(30_000, async (signal, check) => {
    // Bound the queued action itself, not just its caller. The outer deadline
    // also covers waiting to enter the account queue.
    await accountSession.forSession(revision, () => syncRequest(30_000, async (stepSignal, checkStep) => {
      let connecting = false;
      const checkConnection = () => { checkSession(); checkStep(); };
      const stop = () => {
        if (revision !== accountSession.revision) return;
        cancelSyncRequests();
        // Start SDK cancellation before releasing the account queue. Do not wait
        // on a stalled SDK again; its connection manager serializes the cleanup.
        if (connecting) void database.disconnect().catch(error => reportSyncFailure(error, 'sync.retry'));
      };
      stepSignal.addEventListener('abort', stop, { once: true });
      try {
        checkConnection();
        cancelSyncRequests();
        await database.disconnect();
        checkConnection();
        connecting = true;
        await database.connect({
          fetchCredentials: async () => { checkConnection(); const result = await connector.fetchCredentials(); checkConnection(); return result; },
          uploadData: db => { checkConnection(); return connector.uploadData(db); },
        });
        checkConnection();
      } finally {
        stepSignal.removeEventListener('abort', stop);
      }
    }, signal, false));
    checkSession(); check();
  }, undefined, false).catch(error => {
    if (revision === accountSession.revision) reportSyncFailure(error, 'sync.retry');
    throw error;
  });
  pending.set(database, work);
  void work.finally(() => { if (pending.get(database) === work) pending.delete(database); }).catch(() => {});
  return work;
}

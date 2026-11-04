import type { AbstractPowerSyncDatabase, PowerSyncBackendConnector, PowerSyncCredentials } from '@powersync/common';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { accountSession } from '../lib/account-session';
import { cancelSyncRequests, syncRequest } from '../lib/sync-request';

export type ResetDatabase = Pick<AbstractPowerSyncDatabase, 'disconnect' | 'disconnectAndClear' | 'connect' | 'waitForStatus'>;
export const resetSyncKey = (userId: string, scope = 'native') => `sync-reset:${scope}:${userId}`;
export const hasPendingSyncReset = async (key: string) => (await AsyncStorage.getItem(key)) === '1';

/** Discard, never flush, the old queue. A durable marker also protects interrupted resets. */
export async function resetLocalSync<T extends ResetDatabase>(options: {
  database: T;
  markerKey: string;
  connector: PowerSyncBackendConnector;
  fetchCredentials: (signal: AbortSignal) => Promise<PowerSyncCredentials | null>;
  replaceDatabase: (old: T) => Promise<T>;
  clearLocalData: () => Promise<void>;
  onDestructive: () => void;
  onDetached: () => void;
  onRestartRequired: () => void;
}): Promise<T> {
  const startingRevision = accountSession.revision;
  // Check the API before discarding anything. Never hold the account queue while
  // acquiring credentials: legacy token migration can itself use that queue.
  const credentials = await syncRequest(20_000, signal => options.fetchCredentials(signal));
  if (!credentials) throw new Error('Meld je opnieuw aan om de synchronisatie te herstellen.');
  if (accountSession.revision !== startingRevision) throw new Error('Je sessie is gewijzigd.');
  let database = options.database;
  let stalled = false;
  // Report a stalled cleanup without releasing its account/cross-tab locks.
  // Storage operations cannot be cancelled: keep owning them until they settle
  // or the user restarts, and never continue with their late results.
  const requireRestart = () => {
    stalled = true;
    options.onRestartRequired();
  };
  const cleanupTimer = setTimeout(requireRestart, 20_000);
  const transition = accountSession.transition(async () => {
    checkSession();
    options.onDestructive();
    await AsyncStorage.setItem(options.markerKey, '1');
    checkSession();
    cancelSyncRequests();
    // Leave subscriptions mounted until the stream has stopped.
    await database.disconnect();
    checkSession();
    options.onDetached();
    // Closing the old handle prevents late screen callbacks from re-creating
    // discarded edits in the replacement database.
    database = await options.replaceDatabase(database);
    checkSession();
    await database.disconnectAndClear({ clearLocal: true });
    checkSession();
    await options.clearLocalData();
    checkSession();
  });
  const revision = accountSession.revision;
  function checkSession() {
    if (stalled) throw new Error('Het herstellen duurt te lang. Start de app opnieuw.');
    if (accountSession.revision !== revision) throw new Error('Je sessie is gewijzigd.');
  }
  try { await transition; }
  finally { clearTimeout(cleanupTimer); }
  checkSession();
  let firstCredentials: PowerSyncCredentials | null = credentials;
  await syncRequest(60_000, async (signal, check) => {
    await database.connect({
      fetchCredentials: async () => {
        checkSession();
        const fresh = firstCredentials;
        firstCredentials = null;
        const result = fresh ?? await options.connector.fetchCredentials();
        checkSession();
        return result;
      },
      uploadData: db => { checkSession(); return options.connector.uploadData(db); },
    });
    check(); checkSession();
    // disconnectAndClear resets hasSynced, so this must be a fresh checkpoint.
    await database.waitForStatus(status => !!status.connected && !!status.hasSynced
      && !status.dataFlowStatus?.downloadError, signal);
    check(); checkSession(); // waitForStatus also resolves when aborted.
  });
  // Final persistence can stall too. Keep the queue/lock until it settles, just
  // like cleanup, and do not accept a late completion after showing recovery.
  const markerTimer = setTimeout(requireRestart, 20_000);
  try {
    await accountSession.forSession(revision, async () => {
      checkSession();
      await AsyncStorage.removeItem(options.markerKey);
      checkSession();
    });
  } finally { clearTimeout(markerTimer); }
  checkSession();
  return database;
}

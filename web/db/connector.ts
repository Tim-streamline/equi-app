import { syncRequest, syncHttpError } from '@/lib/sync-request';
import { reportSyncFailure } from '@/lib/sync-errors';
import type { AbstractPowerSyncDatabase, PowerSyncBackendConnector } from '@powersync/common';
import { getSession } from './auth';
import { accountSession } from '@/lib/account-session';
import { uploadIntakeAttachments } from '@/lib/intake/attachments/sync';
import { prepareSyncOperations } from '@/lib/intake/sync-operations';
export async function getOrMintToken(signal?: AbortSignal, forceRefresh = false): Promise<string | null> {
  return (await getSession(signal, forceRefresh))?.token ?? null;
}
export class LaravelConnector implements PowerSyncBackendConnector {
  constructor(private readonly userId: string) {}
  async fetchCredentials(signal?: AbortSignal, forceRefresh = false) {
    try {
      return await syncRequest(20_000, async (requestSignal, check) => {
        const session = await getSession(requestSignal, forceRefresh);
        check();
        if (!session) return null;
        if (String(session.user.id) !== this.userId) throw new Error('Je sessie is gewijzigd.');
        // Same-origin proxy also allows HTTPS hosting without exposing private URLs.
        return { endpoint: `${window.location.origin}/powersync`, token: session.token };
      }, signal);
    } catch (error) {
      reportSyncFailure(error, 'sync.credentials');
      throw error;
    }
  }
  async uploadData(database: AbstractPowerSyncDatabase) {
    try {
      await syncRequest(600_000, async (signal, check) => {
        const revision = accountSession.revision;
        const checkSession = () => { check(); if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.'); };
        const batch = await database.getNextCrudTransaction();
        checkSession();
        if (!batch) return;
        const credentials = await this.fetchCredentials(signal);
        if (!credentials) throw new Error('Meld je opnieuw aan.');
        await uploadIntakeAttachments(batch.crud, this.userId, credentials.token, checkSession, signal);
        const operations = await prepareSyncOperations(database, batch.crud);
        checkSession();
        await syncRequest(30_000, async (requestSignal, checkRequest) => {
          checkSession();
          const response = await fetch('/api/sync/upload', {
            method: 'POST', signal: requestSignal,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${credentials.token}` },
            body: JSON.stringify({ timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Amsterdam', operations }),
          });
          checkRequest();
          if (response.status === 401) await getSession(requestSignal, true);
          if (!response.ok) throw syncHttpError(response.status);
        }, signal);
        checkSession();
        await batch.complete();
      });
    } catch (error) {
      reportSyncFailure(error, 'sync.upload');
      throw error;
    }
  }
}

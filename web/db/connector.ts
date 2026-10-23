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
    const session = await getSession(signal, forceRefresh);
    if (!session) return null;
    if (String(session.user.id) !== this.userId) throw new Error('Je sessie is gewijzigd.');
    // Same-origin proxy also allows HTTPS hosting without exposing private URLs.
    return { endpoint: `${window.location.origin}/powersync`, token: session.token };
  }
  async uploadData(database: AbstractPowerSyncDatabase) {
    const revision = accountSession.revision;
    const checkSession = () => { if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.'); };
    const batch = await database.getNextCrudTransaction();
    if (!batch) return;
    const credentials = await this.fetchCredentials();
    if (!credentials) throw new Error('Meld je opnieuw aan.');
    await uploadIntakeAttachments(batch.crud, this.userId, credentials.token, checkSession);
    const operations = await prepareSyncOperations(database, batch.crud);
    checkSession();
    const response = await fetch('/api/sync/upload', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${credentials.token}` },
      body: JSON.stringify({ timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Amsterdam', operations }),
    });
    if (response.status === 401) await getSession(undefined, true);
    if (!response.ok) throw new Error(`Synchroniseren is niet gelukt (${response.status}).`);
    await batch.complete();
  }
}

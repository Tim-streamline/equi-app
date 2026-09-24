import type { AbstractPowerSyncDatabase, PowerSyncBackendConnector } from '@powersync/common';
import { getSession } from './auth';
export async function getOrMintToken(signal?: AbortSignal): Promise<string | null> {
  return (await getSession(signal))?.token ?? null;
}
export class LaravelConnector implements PowerSyncBackendConnector {
  constructor(private readonly userId: string) {}
  async fetchCredentials() {
    const session = await getSession();
    if (!session) return null;
    if (String(session.user.id) !== this.userId) throw new Error('Je sessie is gewijzigd.');
    // Same-origin proxy also allows HTTPS hosting without exposing private URLs.
    return { endpoint: `${window.location.origin}/powersync`, token: session.token };
  }
  async uploadData(database: AbstractPowerSyncDatabase) {
    const batch = await database.getNextCrudTransaction();
    if (!batch) return;
    const credentials = await this.fetchCredentials();
    if (!credentials) throw new Error('Meld je opnieuw aan.');
    const response = await fetch('/api/sync/upload', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${credentials.token}` },
      body: JSON.stringify({ timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Amsterdam', operations: batch.crud.map(op => ({ op: op.op, id: op.id, type: op.table, data: op.opData ?? null })) }),
    });
    if (response.status === 401) await getSession(undefined, true);
    if (!response.ok) throw new Error(`Synchroniseren is niet gelukt (${response.status}).`);
    await batch.complete();
  }
}

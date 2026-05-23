// Bridges PowerSync with the Laravel backend. PowerSync calls
// `fetchCredentials()` periodically (whenever a fresh token is needed) and
// `uploadData()` whenever the local SQLite has pending CRUD operations.
//
// uploadData hits POST /api/sync/upload — the Laravel SyncController
// validates the JWT and applies PUT/PATCH/DELETE through Eloquent.

import type {
  AbstractPowerSyncDatabase,
  PowerSyncBackendConnector,
  PowerSyncCredentials,
} from '@powersync/react-native';

import {
  clearCredentials,
  getApiBaseUrl,
  loadCredentials,
  login as loginRequest,
} from './auth';

// Reuse a single token across rapid uploads. PowerSync re-calls
// fetchCredentials when its internal token expires, so this just avoids
// thrashing /api/auth/login on bursty writes.
type CachedToken = { token: string; expiresAt: number };
let cachedToken: CachedToken | null = null;

async function mintToken(): Promise<{ token: string; endpoint: string; expiresIn: number } | null> {
  const creds = await loadCredentials();
  console.log('[connector] mintToken: have creds?', !!creds);
  if (!creds) return null;
  try {
    console.log('[connector] mintToken: calling login at', getApiBaseUrl());
    const res = await loginRequest(creds.email, creds.password);
    console.log('[connector] mintToken: got token, endpoint =', res.endpoint);
    cachedToken = {
      token: res.token,
      expiresAt: Date.now() + res.expires_in * 1000,
    };
    return { token: res.token, endpoint: res.endpoint, expiresIn: res.expires_in };
  } catch (err) {
    console.warn('[connector] login failed', String(err));
    if (String(err).includes('401')) await clearCredentials();
    return null;
  }
}

async function getOrMintToken(): Promise<string | null> {
  if (cachedToken && cachedToken.expiresAt > Date.now() + 30_000) {
    return cachedToken.token;
  }
  const minted = await mintToken();
  return minted?.token ?? null;
}

export class LaravelConnector implements PowerSyncBackendConnector {
  async fetchCredentials(): Promise<PowerSyncCredentials | null> {
    console.log('[connector] fetchCredentials called');
    const minted = await mintToken();
    if (!minted) {
      console.warn('[connector] fetchCredentials: no minted token');
      return null;
    }
    return { endpoint: minted.endpoint, token: minted.token };
  }

  async uploadData(database: AbstractPowerSyncDatabase): Promise<void> {
    const batch = await database.getNextCrudTransaction();
    if (!batch) return;

    const token = await getOrMintToken();
    if (!token) {
      throw new Error('No PowerSync credentials available for upload');
    }

    const res = await fetch(`${getApiBaseUrl()}/api/sync/upload`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        operations: batch.crud.map((op) => ({
          op: op.op, // PUT | PATCH | DELETE
          id: op.id,
          type: op.table,
          data: op.opData ?? null,
        })),
      }),
    });

    if (res.status === 401) {
      // Token must have expired between fetchCredentials and uploadData.
      cachedToken = null;
      const body = await res.text().catch(() => '');
      throw new Error(`Upload unauthorized (${res.status}): ${body}`);
    }
    if (!res.ok) {
      const body = await res.text().catch(() => '');
      throw new Error(`Upload failed (${res.status}): ${body}`);
    }

    // Only ack the batch once the server confirms persistence.
    await batch.complete();
  }
}

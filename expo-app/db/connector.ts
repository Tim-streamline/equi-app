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

import { accountSession } from '@/lib/account-session';
import { uploadIntakeAttachments } from '@/lib/intake/attachments/sync';
import { prepareSyncOperations } from '@/lib/intake/sync-operations';

import {
  clearCredentials,
  deviceCredentials,
  exchangeDeviceToken,
  getApiBaseUrl,
  loadCredentials,
  login as loginRequest,
  revokeDeviceToken,
  saveCredentials,
  type LoginResponse,
  type StoredCredentials,
} from './auth';

// Reuse a single token across rapid uploads. PowerSync re-calls
// fetchCredentials when its internal token expires, so this just avoids
// thrashing /api/auth/login on bursty writes.
type CachedToken = { token: string; expiresAt: number; userId: string };
let cachedToken: CachedToken | null = null;

type LegacyCredentials = Extract<StoredCredentials, { password: string }>;
// Each password login with a device name creates a device token, so concurrent callers share one
// upgrade instead of each leaving an extra token behind. It runs on its own deadline: one caller
// cancelling must not fail the others.
let legacyUpgrade: Promise<LoginResponse> | null = null;
function upgradeLegacyCredentials(creds: LegacyCredentials, revision: number): Promise<LoginResponse> {
  legacyUpgrade ??= (async () => {
    // A caller that read the password before an earlier upgrade finished uses its token instead.
    const current = await loadCredentials();
    if (current && 'deviceToken' in current && current.userId === creds.userId) return exchangeDeviceToken(current.deviceToken);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20_000);
    try {
      const res = await loginRequest(creds.email, creds.password, controller.signal);
      if (res.device_token && revision === accountSession.revision) {
        try {
          await saveCredentials(deviceCredentials(res));
        } catch {
          // Keep syncing with this login's JWT. The password stays stored, so revoke the token
          // that could not be kept instead of leaving one behind on every retry.
          void revokeDeviceToken(res.device_token);
        }
      }
      return res;
    } finally {
      clearTimeout(timeout);
    }
  })().finally(() => { legacyUpgrade = null; });
  return legacyUpgrade;
}

async function mintToken(signal?: AbortSignal): Promise<{ token: string; endpoint: string; expiresIn: number } | null> {
  const revision = accountSession.revision;
  const creds = await loadCredentials();
  if (signal?.aborted) throw new Error('Request cancelled.');
  console.log('[connector] mintToken: have creds?', !!creds);
  if (!creds) return null;
  try {
    console.log('[connector] mintToken: requesting a token at', getApiBaseUrl());
    // An older app version stored the password: trade it once for a device token, which also
    // deletes it. A backend without device tokens keeps the password in use until it has them.
    const res = 'deviceToken' in creds
      ? await exchangeDeviceToken(creds.deviceToken, signal)
      : await upgradeLegacyCredentials(creds, revision);
    if (signal?.aborted) throw new Error('Request cancelled.');
    if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
    console.log('[connector] mintToken: got token, endpoint =', res.endpoint);
    cachedToken = {
      token: res.token,
      userId: creds.userId,
      expiresAt: Date.now() + res.expires_in * 1000,
    };
    return { token: res.token, endpoint: res.endpoint, expiresIn: res.expires_in };
  } catch (err) {
    // A timed-out request belongs to the old session: never let a late 401
    // clear credentials that may already have been saved by the next login.
    if (signal?.aborted || revision !== accountSession.revision) throw err;
    console.warn('[connector] login failed', String(err));
    if (String(err).includes('Login failed (401)')) {
      await clearCredentials();
      cachedToken = null;
      return null;
    }
    throw new Error('Verbinding niet beschikbaar. Probeer opnieuw.');
  }
}

export async function getOrMintToken(signal?: AbortSignal, forceRefresh = false): Promise<string | null> {
  const creds = await loadCredentials();
  if (signal?.aborted) throw new Error('Request cancelled.');
  if (!creds) return null;
  if (!forceRefresh && cachedToken && cachedToken.userId === creds.userId && cachedToken.expiresAt > Date.now() + 30_000) {
    return cachedToken.token;
  }
  const minted = await mintToken(signal);
  return minted?.token ?? null;
}

export class LaravelConnector implements PowerSyncBackendConnector {
  async fetchCredentials(signal?: AbortSignal): Promise<PowerSyncCredentials | null> {
    console.log('[connector] fetchCredentials called');
    const minted = await mintToken(signal);
    if (!minted) {
      console.warn('[connector] fetchCredentials: no minted token');
      return null;
    }
    return { endpoint: minted.endpoint, token: minted.token };
  }

  async uploadData(database: AbstractPowerSyncDatabase): Promise<void> {
    const revision = accountSession.revision;
    const checkSession = () => { if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.'); };
    const batch = await database.getNextCrudTransaction();
    if (!batch) return;

    const token = await getOrMintToken();
    if (!token) {
      throw new Error('No PowerSync credentials available for upload');
    }

    const credentials = await loadCredentials();
    checkSession();
    if (!credentials) throw new Error('Meld je opnieuw aan.');
    await uploadIntakeAttachments(batch.crud, credentials.userId, token, checkSession);
    const operations = await prepareSyncOperations(database, batch.crud);
    checkSession();

    const res = await fetch(`${getApiBaseUrl()}/api/sync/upload`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Amsterdam',
        operations,
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

import { useCallback, useRef, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { useDb } from '@/db/provider';
import { accountSession } from '@/lib/account-session';

export async function libraryRequest<T>(path: string, method = 'GET', signal?: AbortSignal, body?: object): Promise<T> {
  const sessionRevision = accountSession.revision;
  const token = await getOrMintToken();
  if (accountSession.revision !== sessionRevision) throw new Error('Je sessie is gewijzigd.');
  if (!token) throw new Error('Meld je aan om de bibliotheek te gebruiken.');
  const controller = new AbortController();
  const abort = () => controller.abort();
  signal?.addEventListener('abort', abort);
  if (signal?.aborted) abort();
  const timeout = setTimeout(abort, 20000);
  try {
    const response = await fetch(`${getApiBaseUrl()}/api/library${path}`, {
      method, signal: controller.signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'De wijziging kon niet worden opgeslagen. Probeer opnieuw.');
    return data;
  } catch (error) {
    if (error instanceof Error && error.name === 'AbortError') throw new Error('Verbinding onderbroken. Probeer opnieuw.');
    throw error;
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener('abort', abort);
  }
}

export function useLibraryResource<T>(path: string) {
  const { currentUserId } = useDb();
  const key = `${currentUserId}:${path}`;
  const [snapshot, setSnapshot] = useState<{ key: string; data: T } | null>(null);
  const [failure, setFailure] = useState<{ key: string; message: string } | null>(null);
  const generation = useRef(0);
  const update = useCallback((data: T) => { generation.current++; setSnapshot({ key, data }); setFailure(null); }, [key]);
  const refresh = useCallback(async (signal?: AbortSignal) => {
    const version = ++generation.current;
    if (!currentUserId) return;
    try {
      const data = await libraryRequest<T>(path, 'GET', signal);
      if (!signal?.aborted && generation.current === version) update(data);
    } catch (error) {
      if (!signal?.aborted && generation.current === version) { setSnapshot(null); setFailure({ key, message: error instanceof Error ? error.message : 'Verbinding niet beschikbaar.' }); }
    }
  }, [currentUserId, key, path, update]);
  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    void refresh(controller.signal);
    return () => { generation.current++; controller.abort(); };
  }, [refresh]));
  return { data: snapshot?.key === key ? snapshot.data : null, error: failure?.key === key ? failure.message : null, refresh, update };
}

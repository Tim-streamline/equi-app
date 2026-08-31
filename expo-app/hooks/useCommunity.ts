import { useCallback, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useFocusEffect } from 'expo-router';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { useDb } from '@/db/provider';
import { communityErrorMessage } from '@/lib/community';

export async function communityRequest<T = { ok: boolean }>(path: string, method = 'GET', body?: object | FormData, signal?: AbortSignal): Promise<T> {
  const token = await getOrMintToken();
  if (!token) throw new Error('Meld je aan om Community te gebruiken.');
  const multipart = body instanceof FormData;
  const controller = new AbortController();
  const abort = () => controller.abort();
  signal?.addEventListener('abort', abort);
  if (signal?.aborted) controller.abort();
  const timer = setTimeout(abort, multipart ? 120000 : 20000);
  try {
    const response = await fetch(`${getApiBaseUrl()}${path}`, {
      method, signal: controller.signal,
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(!multipart ? { 'Content-Type': 'application/json' } : {}) },
      ...(body ? { body: multipart ? body : JSON.stringify(body) } : {}),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(communityErrorMessage(response.status, result.message));
    return result as T;
  } catch (error) {
    if (error instanceof Error && error.name === 'AbortError') throw new Error('Verbinding onderbroken. Probeer opnieuw.');
    throw error;
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', abort);
  }
}

/** No disk cache: moderation and account changes must not retain private content. */
export function useCommunityResource<T>(path: string, enabled = true) {
  const { currentUserId } = useDb();
  const key = `${currentUserId}:${path}`;
  const [snapshot, setSnapshot] = useState<{ key: string; data: T } | null>(null);
  const [errorState, setError] = useState<{ key: string; message: string } | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const generation = useRef(0);
  const pending = useRef<AbortController | null>(null);
  const refresh = useCallback(async () => {
    pending.current?.abort();
    const controller = new AbortController();
    pending.current = controller;
    const version = ++generation.current;
    if (!currentUserId || !enabled) return;
    setRefreshing(true);
    try {
      const data = await communityRequest<T>(path, 'GET', undefined, controller.signal);
      if (generation.current !== version) return;
      setSnapshot({ key, data }); setError(null);
    } catch (error) {
      if (generation.current !== version || controller.signal.aborted) return;
      setSnapshot(null);
      setError({ key, message: error instanceof Error ? error.message : 'Verbinding niet beschikbaar.' });
    } finally {
      if (generation.current === version) setRefreshing(false);
    }
  }, [currentUserId, enabled, key, path]);
  useFocusEffect(useCallback(() => {
    void refresh();
    const timer = setInterval(() => { if (AppState.currentState === 'active') void refresh(); }, 15000);
    const listener = AppState.addEventListener('change', state => {
      if (state === 'active') void refresh();
      else { generation.current++; pending.current?.abort(); setSnapshot(null); }
    });
    return () => { clearInterval(timer); listener.remove(); generation.current++; pending.current?.abort(); setSnapshot(null); };
  }, [refresh]));
  return { data: snapshot?.key === key ? snapshot.data : null, error: errorState?.key === key ? errorState.message : null, refresh, refreshing };
}

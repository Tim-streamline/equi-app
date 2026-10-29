import { Platform } from 'react-native';
import type { ImagePickerAsset } from 'expo-image-picker';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { accountSession } from '@/lib/account-session';

export type FeedSubmission = { id: string; status: string; photo_ids: Record<string, string>; message?: string; scan_result_id?: string; error_code?: string; public_result?: FeedResult; product?: { name: string }; workflow_version: number; product_id?: string; product_version_id?: string; submitted_name?: string;
  suggested_name?: string | null;
  recognition?: { decision: string; name?: string; brand?: string; reference_composition?: string; ocr?: { engine?: string; words?: number } }; reference_product_id?: string };
export type FeedResult = { name: string; brand: string; provisional: boolean; score: string | null; score_cap: string | null; message: string;
  missing_data: string[]; findings: { message: string }[]; ingredients: { label: string; explanation?: string; assessment: string; match_status: string; exceptions?: string }[];
  nutrients: { code: string; value: string; unit: string; basis: string; qualifier: string }[] };

export async function scannerRequest<T>(path: string, body?: object | FormData, signal?: AbortSignal): Promise<T> {
  const revision = accountSession.revision;
  const controller = new AbortController();
  const abort = () => controller.abort();
  signal?.addEventListener('abort', abort);
  if (signal?.aborted) abort();
  // Retrying a submission can run both readers for all three photos.
  const timeout = setTimeout(abort, path.endsWith('/analyse') ? 300000 : 90000);
  try {
    const send = async (force = false) => {
      const token = await getOrMintToken(controller.signal, force);
      if (revision !== accountSession.revision || !token) throw new Error('Je sessie is gewijzigd. Meld je opnieuw aan.');
      return fetch(`${getApiBaseUrl()}/api/feed-scanner${path}`, { method: body ? 'POST' : 'GET', signal: controller.signal,
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) },
        ...(body ? { body: body instanceof FormData ? body : JSON.stringify(body) } : {}) });
    };
    let response = await send();
    if (response.status === 401) response = await send(true);
    const data = await response.json().catch(() => { throw new Error('De server is tijdelijk niet bereikbaar. Probeer opnieuw.'); });
    if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
    if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Dit verzoek is niet gelukt. Probeer opnieuw.');
    return data;
  } catch (e) {
    if (e instanceof Error && e.name === 'AbortError') throw new Error('Verbinding onderbroken. Je kunt de inzending terugvinden in je scanhistorie.');
    if (e instanceof TypeError) throw new Error('Geen verbinding. Probeer opnieuw.');
    throw e;
  } finally { clearTimeout(timeout); signal?.removeEventListener('abort', abort); }
}

export async function uploadFeedPhoto(id: string, slot: string, asset: ImagePickerAsset) {
  const form = new FormData();
  form.append('slot', slot);
  if (Platform.OS === 'web') {
    const file = asset.file ?? await (await fetch(asset.uri)).blob();
    form.append('file', file, asset.fileName ?? 'etiket.jpg');
  } else form.append('file', { uri: asset.uri, name: asset.fileName ?? 'etiket.jpg', type: asset.mimeType ?? 'image/jpeg' } as any);
  return scannerRequest<FeedSubmission>(`/submissions/${id}/photos`, form);
}

export const scannerUuid = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });

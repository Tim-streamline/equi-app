import { useEffect, useRef, useState } from 'react';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { useDb } from '@/db/provider';
import { attachmentStorage } from '@/lib/intake/attachments/storage';
import { queuePhotoPreview } from '@/lib/intake/attachments/preview.web';

function getPhotoToken(signal: AbortSignal): Promise<string | null> {
  signal.throwIfAborted();
  return new Promise((resolve, reject) => {
    // An existing shared token request may ignore this caller's signal. Stop
    // waiting locally as well, so cancellation releases the preview queue.
    const abort = () => reject(signal.reason);
    signal.addEventListener('abort', abort, { once: true });
    getOrMintToken(signal).then(resolve, reject)
      .finally(() => signal.removeEventListener('abort', abort));
  });
}

/** Original bytes stay intact for upload/review. Only a small preview enters the DOM. */
export function Photo({ reference, expanded = false, asset }: { reference: string; expanded?: boolean; asset?: { uri: string; file?: File } }) {
  const { currentUserId } = useDb();
  const container = useRef<HTMLDivElement>(null);
  const [nearViewport, setNearViewport] = useState(false);
  const [source, setSource] = useState<{ key: string; uri: string }>();
  const [error, setError] = useState<string>();
  const [retry, setRetry] = useState(0);
  const key = `${currentUserId}:${reference}:${asset?.uri ?? ''}`;
  useEffect(() => {
    if (!container.current) return;
    if (typeof IntersectionObserver === 'undefined') { setNearViewport(true); return; }
    const observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) {
        setNearViewport(true);
        observer.disconnect();
      }
    }, { rootMargin: '300px' });
    observer.observe(container.current);
    return () => observer.disconnect();
  }, []);
  useEffect(() => {
    if (!nearViewport || !currentUserId) return;
    const controller = new AbortController();
    const { signal } = controller;
    let objectUrl: string | undefined;
    setError(undefined);
    const timeout = setTimeout(() => { setError(key); controller.abort(); }, 30000);
    void queuePhotoPreview(async () => {
      if (asset?.file) return asset.file;
      if (asset?.uri) return (await fetch(asset.uri, { signal })).blob();
      const local = await attachmentStorage.get(currentUserId, reference.split(':')[1]).catch(() => null);
      signal.throwIfAborted();
      if (local?.blob) return local.blob;
      const token = await getPhotoToken(signal);
      signal.throwIfAborted();
      if (!token) throw new Error('Meld je opnieuw aan om de foto te bekijken.');
      const response = await fetch(`${getApiBaseUrl()}/api/intake-media/${reference.split(':')[1]}?preview=1`, {
        headers: { Authorization: `Bearer ${token}` }, signal,
      });
      if (!response.ok) throw new Error('De foto kon niet worden geladen.');
      const blob = await response.blob();
      // During a rolling deployment an older API may ignore the preview flag.
      return response.headers.get('X-Intake-Preview') === '1' ? { preview: blob } : blob;
    }, signal, asset ? undefined : { userId: currentUserId, id: reference.split(':')[1] }).then(blob => {
      if (signal.aborted) return;
      objectUrl = URL.createObjectURL(blob);
      setSource({ key, uri: objectUrl });
    }).catch(() => { if (!signal.aborted) setError(key); }).finally(() => clearTimeout(timeout));
    return () => { clearTimeout(timeout); controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
    // Attachment IDs identify immutable bytes. Upload-status writes must not
    // trigger another read/decode of this photo (or any other photo on the page).
  }, [currentUserId, reference, key, nearViewport, retry, asset]);
  let name = 'Bijlage';
  try { name = decodeURIComponent(reference.split(':').slice(2).join(':')) || name; } catch { /* Legacy name. */ }
  return <div ref={container} style={{ width: expanded ? '100%' : 80, height: expanded ? 240 : 80,
    borderRadius: 12, backgroundColor: '#f2f6f5', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }}>
    {source?.key === key ? <img src={source.uri} alt={name} loading="lazy" decoding="async"
      style={{ width: '100%', height: '100%', objectFit: expanded ? 'contain' : 'cover' }} />
      : error === key ? <button type="button" onClick={() => setRetry(value => value + 1)}>Foto opnieuw laden</button>
        : <span role="img" aria-label={`Foto laden: ${name}`} style={{ color: '#108a82' }}>▧</span>}
  </div>;
}

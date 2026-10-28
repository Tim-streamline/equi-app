import { previewCache, type PreviewKey } from './preview-cache.web';

// A page can contain many camera originals. Only one original may be decoded at
// a time, and only the small resulting JPEG is retained by the preview component.
let pending: Promise<unknown> = Promise.resolve();
export const PREVIEW_MAX_EDGE = 960;

export function previewDimensions(width: number, height: number) {
  const scale = Math.min(1, PREVIEW_MAX_EDGE / Math.max(width, height));
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

async function resize(blob: Blob, signal: AbortSignal): Promise<Blob> {
  signal.throwIfAborted();
  let bitmap: ImageBitmap | undefined;
  let image: HTMLImageElement | undefined;
  let objectUrl: string | undefined;
  const canvas = document.createElement('canvas');
  try {
    if (typeof createImageBitmap === 'function') {
      bitmap = await createImageBitmap(blob);
    } else {
      // Older Safari: do not use HTMLImageElement.decode(), which forces eager
      // full-size decoding. This fallback still runs inside the single-file queue.
      image = new Image();
      objectUrl = URL.createObjectURL(blob);
      await new Promise<void>((resolve, reject) => {
        const abort = () => { image!.src = ''; finish(signal.reason); };
        const finish = (error?: unknown) => {
          signal.removeEventListener('abort', abort);
          image!.onload = null; image!.onerror = null;
          error ? reject(error) : resolve();
        };
        image!.onload = () => finish();
        image!.onerror = () => finish(new Error('De foto kon niet worden geladen.'));
        signal.addEventListener('abort', abort, { once: true });
        image!.src = objectUrl!;
      });
    }
    signal.throwIfAborted();
    const source = bitmap ?? image!;
    const size = previewDimensions(bitmap?.width ?? image!.naturalWidth, bitmap?.height ?? image!.naturalHeight);
    canvas.width = size.width; canvas.height = size.height;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('De foto kon niet worden geladen.');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, size.width, size.height);
    context.drawImage(source, 0, 0, size.width, size.height);
    // Release the full-size decode before waiting for the thumbnail encoder.
    bitmap?.close(); bitmap = undefined;
    if (image) image.src = '';
    return await new Promise<Blob>((resolve, reject) => canvas.toBlob(value => {
      if (signal.aborted) reject(signal.reason);
      else if (value) resolve(value);
      else reject(new Error('De foto kon niet worden geladen.'));
    }, 'image/jpeg', 0.82));
  } finally {
    bitmap?.close();
    if (image) image.src = '';
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    canvas.width = 0; canvas.height = 0;
  }
}

export function queuePhotoPreview(load: () => Promise<Blob | { preview: Blob }>, signal: AbortSignal, key?: PreviewKey): Promise<Blob> {
  const result = pending.then(async () => {
    signal.throwIfAborted();
    // Reading a thumbnail must happen before reading the much larger original.
    // Storage is best-effort: private mode/quota failures still permit viewing.
    const cached = key ? await previewCache.get(key).catch(() => undefined) : undefined;
    signal.throwIfAborted();
    if (cached) return cached;
    const source = await load();
    signal.throwIfAborted();
    const preview = source instanceof Blob ? await resize(source, signal) : source.preview;
    signal.throwIfAborted();
    if (key) await previewCache.put(key, preview).catch(() => undefined);
    signal.throwIfAborted();
    return preview;
  });
  // A failed/cancelled image must not block previews for the remaining photos.
  pending = result.then(() => undefined, () => undefined);
  return result;
}

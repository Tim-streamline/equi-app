import { Image } from 'expo-image';

const pending = new Map<string, Promise<boolean>>();
const listeners = new Set<(uri: string) => void>();

export function onThumbnailCached(listener: (uri: string) => void) {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

async function cacheThumbnail(uri: string): Promise<boolean> {
  const existing = pending.get(uri);
  if (existing) return existing;
  const request = (async () => {
    try {
      // Check the real disk cache each time: the OS may have evicted a file.
      const cached = await Image.getCachePathAsync(uri);
      const ready = !!cached || await Image.prefetch(uri, 'disk');
      if (ready) listeners.forEach(listener => listener(uri));
      return ready;
    } catch {
      // An unavailable backend must not stop other thumbnails or app startup.
      return false;
    }
  })();
  pending.set(uri, request);
  try {
    return await request;
  } finally {
    pending.delete(uri);
  }
}

export async function preloadLibraryThumbnails(
  urls: readonly string[],
  isCancelled: () => boolean = () => false,
) {
  const queue = [...new Set(urls.filter(uri => /^https?:\/\//i.test(uri)))];
  let next = 0;
  const worker = async () => {
    while (!isCancelled() && next < queue.length) {
      await cacheThumbnail(queue[next++]);
    }
  };
  await Promise.all(Array.from({ length: Math.min(3, queue.length) }, worker));
}

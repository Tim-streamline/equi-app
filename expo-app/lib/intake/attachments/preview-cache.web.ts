/** Disposable, owner-scoped thumbnails, separate from the immutable upload originals. */
export type PreviewKey = { userId: string; id: string };
function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('equi-intake-photo-previews-v1', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('previews');
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
  });
}
export const previewCache = {
  async get(key: PreviewKey): Promise<Blob | undefined> {
    const db = await open();
    try {
      return await new Promise((resolve, reject) => {
        const request = db.transaction('previews').objectStore('previews').get([key.userId, key.id]);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      });
    } finally { db.close(); }
  },
  async put(key: PreviewKey, blob: Blob): Promise<void> {
    const db = await open();
    try {
      await new Promise<void>((resolve, reject) => {
        const tx = db.transaction('previews', 'readwrite');
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error);
        tx.onerror = () => reject(tx.error);
        tx.objectStore('previews').put(blob, [key.userId, key.id]);
      });
    } finally { db.close(); }
  },
};

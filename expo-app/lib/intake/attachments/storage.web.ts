import type { AttachmentAsset, LocalAttachment } from './types';
import { attachmentsChanged } from './events';

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('equi-intake-attachments', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('files', { keyPath: ['userId', 'id'] });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}
export const attachmentStorage = {
  async get(userId: string, id: string): Promise<LocalAttachment | null> {
    const db = await open();
    try {
      return await new Promise((resolve, reject) => {
        const request = db.transaction('files', 'readonly').objectStore('files').get([userId, id]);
        request.onsuccess = () => resolve(request.result ?? null);
        request.onerror = () => reject(request.error);
      });
    } finally { db.close(); }
  },
  async put(record: LocalAttachment) {
    const db = await open();
    try {
      await new Promise<void>((resolve, reject) => {
        const tx = db.transaction('files', 'readwrite');
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error ?? new Error('Foto kon niet op dit apparaat worden opgeslagen.'));
        tx.onerror = () => reject(tx.error);
        tx.objectStore('files').put(record);
      });
      attachmentsChanged({ userId: record.userId, id: record.id });
    } finally { db.close(); }
  },
};
export async function saveAttachment(record: LocalAttachment, asset: AttachmentAsset) {
  const blob = asset.file ?? await (await fetch(asset.uri)).blob();
  if (blob.size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
  await attachmentStorage.put({ ...record, blob });
}

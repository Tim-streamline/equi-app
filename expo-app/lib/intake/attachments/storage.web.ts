import type { AttachmentAsset, LocalAttachment } from './types';
import { attachmentsChanged } from './events';
import { attachmentWriteCheck, resetAttachments, writeAttachment } from './storage-writes';
import { browserTabId } from '../../browser-tab';

function open(legacy = false): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    // Keep the old store intact: a closed/restored tab may still reference its
    // unscoped files. New writes cannot establish exclusive ownership of them.
    const request = indexedDB.open(legacy ? 'equi-intake-attachments' : 'equi-intake-attachments-scoped', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('files', {
      keyPath: legacy ? ['userId', 'id'] : ['userId', 'tabId', 'id'],
    });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}
async function read(key: IDBValidKey, legacy = false): Promise<LocalAttachment | null> {
  const db = await open(legacy);
  try {
    return await new Promise((resolve, reject) => {
      const request = db.transaction('files', 'readonly').objectStore('files').get(key);
      request.onsuccess = () => resolve(request.result ?? null);
      request.onerror = () => reject(request.error);
    });
  } finally { db.close(); }
}
export const attachmentStorage = {
  async get(userId: string, id: string): Promise<LocalAttachment | null> {
    return await read([userId, browserTabId(), id]) ?? await read([userId, id], true);
  },
  async put(record: LocalAttachment) {
    const tabId = browserTabId();
    return writeAttachment(record.userId, async () => {
      const db = await open();
      try {
        await new Promise<void>((resolve, reject) => {
          const tx = db.transaction('files', 'readwrite');
          tx.oncomplete = () => resolve();
          tx.onabort = () => reject(tx.error ?? new Error('Foto kon niet op dit apparaat worden opgeslagen.'));
          tx.onerror = () => reject(tx.error);
          tx.objectStore('files').put({ ...record, tabId });
        });
        attachmentsChanged({ userId: record.userId, id: record.id });
      } finally { db.close(); }
    });
  },
};
export async function clearAttachments(userId: string) {
  const tabId = browserTabId();
  await resetAttachments(userId, async () => {
    const db = await open();
    try {
      await new Promise<void>((resolve, reject) => {
        const tx = db.transaction('files', 'readwrite');
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error ?? new Error('Lokale bestanden konden niet worden gewist.'));
        tx.onerror = () => reject(tx.error);
        const cursor = tx.objectStore('files').openCursor();
        cursor.onsuccess = () => {
          const row = cursor.result;
          if (!row) return;
          if (row.value.userId === userId && row.value.tabId === tabId) row.delete();
          row.continue();
        };
      });
    } finally { db.close(); }
  });
}
export async function saveAttachment(record: LocalAttachment, asset: AttachmentAsset) {
  const check = attachmentWriteCheck(record.userId);
  const blob = asset.file ?? await (await fetch(asset.uri)).blob();
  check();
  if (blob.size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
  await attachmentStorage.put({ ...record, blob });
}

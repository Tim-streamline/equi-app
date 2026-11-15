import type { AttachmentAsset, LocalAttachment } from './types';
import { attachmentsChanged } from './events';
import { attachmentWriteCheck, resetAttachments, writeAttachment } from './storage-writes';
import { browserTabId } from '../../browser-tab';

const stores = {
  // Keep the old store intact: a closed/restored tab may still reference its
  // unscoped files. New writes cannot establish exclusive ownership of them.
  legacy: { database: 'equi-intake-attachments', store: 'files', keyPath: ['userId', 'id'] },
  files: { database: 'equi-intake-attachments-scoped', store: 'files', keyPath: ['userId', 'tabId', 'id'] },
  // Upload status lives apart from the bytes: recording an attempt must never
  // rewrite a photo. Safari can fail to store a file it read from IndexedDB.
  status: { database: 'equi-intake-attachment-status', store: 'status', keyPath: ['userId', 'tabId', 'id'] },
} as const;
type Kind = keyof typeof stores;
type Status = Pick<LocalAttachment, 'uploaded' | 'error' | 'rejections' | 'rejected'>;
/** Bytes that came out of storage: they are already saved and are not written again. */
const stored = new WeakSet<Blob>();

function open(kind: Kind): Promise<IDBDatabase> {
  const { database, store, keyPath } = stores[kind];
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(database, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(store, { keyPath: [...keyPath] });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}
async function read<T>(kind: Kind, key: IDBValidKey): Promise<T | null> {
  const db = await open(kind);
  try {
    return await new Promise((resolve, reject) => {
      const request = db.transaction(stores[kind].store, 'readonly').objectStore(stores[kind].store).get(key);
      request.onsuccess = () => resolve(request.result ?? null);
      request.onerror = () => reject(request.error);
    });
  } finally { db.close(); }
}
async function write(kind: Kind, failure: string, change: (store: IDBObjectStore) => void) {
  const db = await open(kind);
  try {
    await new Promise<void>((resolve, reject) => {
      const tx = db.transaction(stores[kind].store, 'readwrite');
      // A failed request reaches the transaction before it aborts, while tx.error
      // is still null. Never reject without an error: callers report and rethrow it.
      const fail = (event: Event) => reject(tx.error
        ?? (event.target as { error?: DOMException | null } | null)?.error ?? new Error(failure));
      tx.oncomplete = () => resolve();
      tx.onabort = fail;
      tx.onerror = fail;
      change(tx.objectStore(stores[kind].store));
    });
  } finally { db.close(); }
}
function removeTab(userId: string, tabId: string) {
  return (store: IDBObjectStore) => {
    const cursor = store.openCursor();
    cursor.onsuccess = () => {
      const row = cursor.result;
      if (!row) return;
      if (row.value.userId === userId && row.value.tabId === tabId) row.delete();
      row.continue();
    };
  };
}
export const attachmentStorage = {
  async get(userId: string, id: string): Promise<LocalAttachment | null> {
    const tabId = browserTabId();
    const file = await read<LocalAttachment>('files', [userId, tabId, id]) ?? await read<LocalAttachment>('legacy', [userId, id]);
    if (!file) return null;
    if (file.blob) stored.add(file.blob);
    const status = await read<Status>('status', [userId, tabId, id]);
    return status ? { ...file, uploaded: status.uploaded, error: status.error, rejections: status.rejections, rejected: status.rejected } : file;
  },
  async put(record: LocalAttachment) {
    const tabId = browserTabId();
    return writeAttachment(record.userId, async () => {
      const key = [record.userId, tabId, record.id];
      if (record.blob && stored.has(record.blob)) {
        await write('status', 'De uploadstatus kon niet op dit apparaat worden opgeslagen.', store =>
          store.put({ userId: record.userId, tabId, id: record.id, uploaded: record.uploaded, error: record.error,
            rejections: record.rejections, rejected: record.rejected }));
      } else {
        // New bytes carry their own status; drop what described the bytes they replace.
        await write('status', 'Foto kon niet op dit apparaat worden opgeslagen.', store => store.delete(key));
        await write('files', 'Foto kon niet op dit apparaat worden opgeslagen.', store => store.put({ ...record, tabId }));
      }
      attachmentsChanged({ userId: record.userId, id: record.id });
    });
  },
};
async function all<T>(kind: Kind): Promise<T[]> {
  const db = await open(kind);
  try {
    return await new Promise((resolve, reject) => {
      const request = db.transaction(stores[kind].store, 'readonly').objectStore(stores[kind].store).getAll();
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  } finally { db.close(); }
}
/**
 * Tabs whose stored files still wait for upload, the tab with the newest file first.
 * A tab that was closed before its identity was remembered can only be found again
 * through the files it left here.
 */
export async function waitingAttachmentTabs(userId?: string | null): Promise<string[]> {
  type Row = LocalAttachment & { tabId: string };
  const key = (row: { userId: string; tabId: string; id: string }) => JSON.stringify([row.userId, row.tabId, row.id]);
  const statuses = new Map((await all<Status & { userId: string; tabId: string; id: string }>('status')).map(row => [key(row), row]));
  const tabs = new Map<string, { newest: number; files: number }>();
  for (const file of await all<Row>('files')) {
    if (userId && file.userId !== userId) continue;
    const status = statuses.get(key(file)) ?? file;
    if (status.uploaded || status.rejected) continue;
    const tab = tabs.get(file.tabId) ?? { newest: 0, files: 0 };
    const modified = (file.blob as Partial<File> | undefined)?.lastModified;
    tabs.set(file.tabId, { newest: Math.max(tab.newest, typeof modified === 'number' ? modified : 0), files: tab.files + 1 });
  }
  return [...tabs].sort(([, a], [, b]) => b.newest - a.newest || b.files - a.files).map(([tabId]) => tabId);
}
export async function clearAttachments(userId: string) {
  const tabId = browserTabId();
  await resetAttachments(userId, async () => {
    await write('files', 'Lokale bestanden konden niet worden gewist.', removeTab(userId, tabId));
    await write('status', 'Lokale bestanden konden niet worden gewist.', removeTab(userId, tabId));
  });
}
export async function saveAttachment(record: LocalAttachment, asset: AttachmentAsset) {
  const check = attachmentWriteCheck(record.userId);
  const blob = asset.file ?? await (await fetch(asset.uri)).blob();
  check();
  if (blob.size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
  await attachmentStorage.put({ ...record, blob });
}

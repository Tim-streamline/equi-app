import { Directory, File, Paths } from 'expo-file-system';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type { AttachmentAsset, LocalAttachment } from './types';
import { attachmentsChanged } from './events';
import { resetAttachments, writeAttachment } from './storage-writes';

function directory(userId: string) {
  const dir = new Directory(Paths.document, 'intake-attachments', encodeURIComponent(userId));
  dir.create({ intermediates: true, idempotent: true });
  return dir;
}
const key = (userId: string, id: string) => `intake-attachment:${userId}:${id}`;
export const attachmentStorage = {
  async get(userId: string, id: string): Promise<LocalAttachment | null> {
    const value = await AsyncStorage.getItem(key(userId, id));
    return value ? JSON.parse(value) : null;
  },
  async put(record: LocalAttachment) {
    await writeAttachment(record.userId, () => AsyncStorage.setItem(key(record.userId, record.id), JSON.stringify(record)));
    attachmentsChanged({ userId: record.userId, id: record.id });
  },
};
export async function clearAttachments(userId: string) {
  await resetAttachments(userId, async () => {
    const keys = await AsyncStorage.getAllKeys();
    await AsyncStorage.multiRemove(keys.filter(value => value.startsWith(`intake-attachment:${userId}:`)));
    const dir = new Directory(Paths.document, 'intake-attachments', encodeURIComponent(userId));
    if (dir.exists) dir.delete();
  });
}
export async function saveAttachment(record: LocalAttachment, asset: AttachmentAsset) {
  const file = new File(asset.uri);
  if (file.size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
  const destination = new File(directory(record.userId), record.id);
  // Keep file bytes in documents, not the picker cache that the OS can evict.
  file.copy(destination);
  await attachmentStorage.put({ ...record, uri: destination.uri });
}

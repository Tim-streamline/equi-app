import { Directory, File, Paths } from 'expo-file-system';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type { AttachmentAsset, LocalAttachment } from './types';
import { attachmentsChanged } from './events';

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
    await AsyncStorage.setItem(key(record.userId, record.id), JSON.stringify(record));
    attachmentsChanged();
  },
};
export async function saveAttachment(record: LocalAttachment, asset: AttachmentAsset) {
  const file = new File(asset.uri);
  if (file.size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
  const destination = new File(directory(record.userId), record.id);
  // Keep file bytes in documents, not the picker cache that the OS can evict.
  file.copy(destination);
  await attachmentStorage.put({ ...record, uri: destination.uri });
}

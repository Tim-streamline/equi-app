import AsyncStorage from '@react-native-async-storage/async-storage';
import { clearAttachments } from '@/lib/intake/attachments/storage';

export async function clearLocalSyncData(userId: string) {
  await clearAttachments(userId);
  const keys = await AsyncStorage.getAllKeys();
  await AsyncStorage.multiRemove(keys.filter(key => {
    const match = /^horse-dashboard:v\d+:([^:]+):/.exec(key);
    return match?.[1] === userId;
  }));
}

import { useEffect, useState } from 'react';
import { View, Image } from 'react-native';
import { Camera } from 'lucide-react-native';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { useDb } from '@/db/provider';
import { attachmentStorage } from '@/lib/intake/attachments/storage';

export function Photo({ reference, expanded = false, asset }: {
  reference: string; expanded?: boolean; asset?: { uri: string; file?: File };
}) {
  const { currentUserId } = useDb();
  const key = `${currentUserId}:${reference}:${asset?.uri ?? ''}`;
  const [source, setSource] = useState<{ key: string; uri: string; headers?: Record<string, string> }>();
  useEffect(() => {
    let active = true;
    void (async () => {
      if (asset) { setSource({ key, uri: asset.uri }); return; }
      if (!currentUserId) return;
      const local = await attachmentStorage.get(currentUserId, reference.split(':')[1]);
      if (!active) return;
      if (local?.uri) { setSource({ key, uri: local.uri }); return; }
      const token = await getOrMintToken();
      if (!token || !active) return;
      setSource({ key, uri: `${getApiBaseUrl()}/api/intake-media/${reference.split(':')[1]}`,
        headers: { Authorization: `Bearer ${token}` } });
    })().catch(() => {});
    return () => { active = false; };
  }, [currentUserId, reference, key, asset]);
  let name = 'Bijlage';
  try { name = decodeURIComponent(reference.split(':').slice(2).join(':')) || name; } catch { /* Legacy name. */ }
  return source?.key === key ? <Image source={source} accessibilityLabel={name} resizeMode={expanded ? 'contain' : 'cover'}
    style={expanded ? { width: '100%', height: 240, borderRadius: 12, backgroundColor: '#f2f6f5' } : { width: 80, height: 80, borderRadius: 12 }} />
    : <View style={expanded ? { width: '100%', height: 240, alignItems: 'center', justifyContent: 'center' } : undefined}><Camera size={24} color="#108a82" /></View>;
}

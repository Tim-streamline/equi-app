import { useEffect, useState } from 'react';
import { Image, Pressable, View, Text } from 'react-native';
import { VideoView, useVideoPlayer } from 'expo-video';
import { Play } from 'lucide-react-native';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import type { CommunityMedia } from '@/lib/community';
import { CommunityDialog } from './Common';

function CommunityVideo({ uri, token }: { uri: string; token: string }) {
  const player = useVideoPlayer({ uri, headers: { Authorization: `Bearer ${token}` } });
  return <VideoView player={player} nativeControls contentFit="contain" style={{ width: '100%', height: 320 }} />;
}

export function MediaGallery({ media }: { media: CommunityMedia[] }) {
  const [token, setToken] = useState<string | null>(null);
  const [selected, setSelected] = useState<CommunityMedia | null>(null);
  useEffect(() => { let active = true; void getOrMintToken().then(value => { if (active) setToken(value); }); return () => { active = false; }; }, [media]);
  if (!media.length || !token) return null;
  return <>
    <View className="mt-3 flex-row flex-wrap gap-2">
      {media.map(file => <Pressable key={file.id} accessibilityLabel={file.type === 'video' ? 'Bekijk video' : 'Bekijk foto'} onPress={() => setSelected(file)}
        style={{ width: media.length === 1 ? '100%' : '48%', height: 180, borderRadius: 14, overflow: 'hidden', backgroundColor: '#E4F1EE', alignItems: 'center', justifyContent: 'center' }}>
        {file.type === 'image' ? <Image source={{ uri: getApiBaseUrl() + file.path, headers: { Authorization: `Bearer ${token}` }, cache: 'reload' }} resizeMode="cover" style={{ width: '100%', height: '100%' }} />
          : <><Play size={32} color="#127A79" /><Text className="mt-2 text-teal-700">Bekijk video</Text></>}
      </Pressable>)}
    </View>
    <CommunityDialog open={!!selected} close={() => setSelected(null)} title={selected?.type === 'video' ? 'Video' : 'Foto'}>
      {selected && (selected.type === 'video' ? <CommunityVideo uri={getApiBaseUrl() + selected.path} token={token} />
        : <Image source={{ uri: getApiBaseUrl() + selected.path, headers: { Authorization: `Bearer ${token}` }, cache: 'reload' }} resizeMode="contain" style={{ width: '100%', height: 500 }} />)}
    </CommunityDialog>
  </>;
}

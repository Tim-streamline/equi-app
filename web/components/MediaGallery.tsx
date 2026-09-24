import { useState } from 'react';
import { Image, Pressable, View, Text } from 'react-native';
import { Play } from 'lucide-react-native';
import type { CommunityMedia } from '@/lib/community';
import { CommunityDialog } from '@/components/community/Common';

// Browser image/video elements cannot attach a bearer header. This read-only
// endpoint uses the HttpOnly session and the existing media access policy.
const mediaUrl = (file: CommunityMedia) => `/web-session/media/${encodeURIComponent(file.id)}`;
export function MediaGallery({ media }: { media: CommunityMedia[] }) {
  const [selected, setSelected] = useState<CommunityMedia | null>(null);
  return <>
    <View className="mt-3 flex-row flex-wrap gap-2">
      {media.map(file => <Pressable key={file.id} accessibilityRole="button" accessibilityLabel={file.type === 'video' ? 'Bekijk video' : 'Bekijk foto'} onPress={() => setSelected(file)}
        style={{ width: media.length === 1 ? '100%' : '48%', height: 180, borderRadius: 14, overflow: 'hidden', backgroundColor: '#E4F1EE', alignItems: 'center', justifyContent: 'center' }}>
        {file.type === 'image' ? <Image source={{ uri: mediaUrl(file) }} resizeMode="cover" style={{ width: '100%', height: '100%' }} />
          : <><Play size={32} color="#127A79" /><Text className="mt-2 text-teal-700">Bekijk video</Text></>}
      </Pressable>)}
    </View>
    <CommunityDialog open={!!selected} close={() => setSelected(null)} title={selected?.type === 'video' ? 'Video' : 'Foto'}>
      {selected && (selected.type === 'video' ? <video src={mediaUrl(selected)} controls playsInline style={{ width: '100%', maxHeight: '75vh' }} />
        : <Image source={{ uri: mediaUrl(selected) }} resizeMode="contain" style={{ width: '100%', height: 500 }} />)}
    </CommunityDialog>
  </>;
}

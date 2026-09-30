import { useEffect, useState } from 'react';
import { View, type StyleProp, type ViewStyle } from 'react-native';
import { Image } from 'expo-image';
import { onThumbnailCached } from '@/lib/library-thumbnail-cache';
import { BookOpen, Film, Music } from 'lucide-react-native';

export function LibraryThumbnail({ uri, format = 'article', style }: {
  uri?: string | null;
  format?: string;
  style?: StyleProp<ViewStyle>;
}) {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [uri]);
  useEffect(() => {
    if (!failed) return;
    return onThumbnailCached(cachedUri => {
      if (cachedUri === uri) setFailed(false);
    });
  }, [uri, failed]);
  const Icon = format === 'video' ? Film : ['audio', 'podcast'].includes(format) ? Music : BookOpen;
  return (
    <View style={[{ width: '100%', aspectRatio: 4 / 3, overflow: 'hidden', borderRadius: 12,
      backgroundColor: '#0D5C5B', alignItems: 'center', justifyContent: 'center' }, style]}>
      <Icon size={32} color="rgba(255,255,255,0.65)" />
      {!!uri && !failed && <Image key={uri} source={{ uri }} contentFit="cover" cachePolicy="disk" onError={() => setFailed(true)}
        style={{ position: 'absolute', width: '100%', height: '100%' }} />}
    </View>
  );
}

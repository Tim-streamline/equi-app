import { Pressable, Text, View } from 'react-native';
import { router } from 'expo-router';
import { BookOpen, Headphones, LockKeyhole, Play } from 'lucide-react-native';
import { LibraryThumbnail } from './LibraryThumbnail';
import { LibraryBookmarkButton } from './LibraryBookmarkButton';
import { libraryAccessLabel, libraryFormat, libraryPath, type LibraryAccess, type LibraryCardItem } from '@/lib/library';

export function LibraryCard({ item, access, compact = false, showBookmark = false }: {
  item: LibraryCardItem; access?: LibraryAccess | null; compact?: boolean; showBookmark?: boolean;
}) {
  const Icon = item.format === 'video' ? Play : ['audio', 'podcast'].includes(item.format ?? '') ? Headphones : BookOpen;
  const state = libraryAccessLabel(item, access);
  return <View style={{ width: '48.5%' }} className="overflow-hidden rounded-2xl border border-ink-8 bg-white">
    <Pressable accessibilityRole="link" accessibilityLabel={`${item.title}, ${libraryFormat(item.format)}${state.locked ? ', Vergrendeld' : ''}${state.label ? `, ${state.label}` : ''}`}
      onPress={() => router.push(libraryPath(item) as any)} className="flex-1 p-2">
      <View>
        <LibraryThumbnail uri={item.heroImageUrl} format={item.format} style={compact ? { maxHeight: 160 } : undefined} />
        <View className="absolute bottom-1.5 right-1.5 flex-row items-center gap-1 rounded-lg bg-white px-1.5 py-1" style={{ maxWidth: '95%' }}>
          <Icon size={12} color="#127A79" />
          <Text numberOfLines={1} className="shrink font-semi text-[10px] text-teal-700">{libraryFormat(item.format)}{item.durationLabel ? ` · ${item.durationLabel}` : ''}</Text>
        </View>
      </View>
      <View className="flex-1 px-1 pb-1 pt-2">
        <Text className="font-semi text-[14px] leading-[19px] text-ink" style={{ minHeight: 38 }} numberOfLines={2} ellipsizeMode="tail">{item.title}</Text>
        {!compact && <Text className="mt-1 text-[12px] leading-[17px] text-ink-70" style={{ minHeight: 51 }} numberOfLines={3} ellipsizeMode="tail">{item.description ?? ''}</Text>}
        {!!state.label && <View className="mt-2 flex-row items-center gap-1">
          {state.locked && <LockKeyhole size={11} color="#127A79" />}
          <Text className="text-[11px] text-ink-50">{state.label}</Text>
        </View>}
      </View>
    </Pressable>
    {showBookmark && <View className="absolute right-2 top-2 rounded-full bg-white/95"><LibraryBookmarkButton itemId={item.id} /></View>}
  </View>;
}

import { Pressable, Text, View } from 'react-native';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { type LibraryAccess, type LibraryCardItem } from '@/lib/library';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { LibraryCard } from './LibraryCard';

export function RelatedLibraryItems({ itemId }: { itemId: string }) {
  const { data, error, refresh } = useLibraryResource<{ items: LibraryCardItem[]; access: LibraryAccess }>(`/${encodeURIComponent(itemId)}/related`);
  if (!data && !error) return null;
  if (data?.items.length === 0) return null;
  return <View className="mt-6">
    <SectionTitle>VERDER KIJKEN</SectionTitle>
    {error && !data ? <Pressable accessibilityRole="button" onPress={() => void refresh()} className="px-5 py-3"><Text className="text-ink-50">Suggesties konden niet worden geladen. Tik om opnieuw te proberen.</Text></Pressable>
      : <View className="flex-row flex-wrap items-stretch justify-between gap-y-3 px-4">
        {data?.items.map(item => <LibraryCard key={item.id} item={item} access={data.access} />)}
      </View>}
  </View>;
}

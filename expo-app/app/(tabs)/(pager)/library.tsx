import { useEffect, useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable, TextInput } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search } from 'lucide-react-native';
import { LibraryThumbnail } from '@/components/library/LibraryThumbnail';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryCategories,
  useLibraryItemCategories,
  useLibraryItems,
  useValue,
} from '@/db/hooks';
import {
  ALL_LIBRARY_FILTER_ID,
  filterLibraryItems,
  toggleLibraryCategory,
} from '@/lib/library-filter';

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const list = useLibraryItems();
  const itemCategories = useLibraryItemCategories();
  const placeholder = useValue('librarySearchPlaceholder') as string;
  const [activeCategoryIds, setActiveCategoryIds] = useState<string[]>([]);
  const { q, t } = useLocalSearchParams<{ q?: string; t?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  useEffect(() => {
    if (typeof q === 'string') {
      setSearchQuery(q);
      setActiveCategoryIds([]);
    }
  }, [q, t]);
  const filteredList = useMemo(
    () => filterLibraryItems(list, itemCategories, activeCategoryIds, searchQuery),
    [list, itemCategories, activeCategoryIds, searchQuery],
  );

  const toggleCategory = (categoryId: string) => {
    setActiveCategoryIds((current) =>
      toggleLibraryCategory(current, categoryId),
    );
  };

  return (
    <View className="flex-1">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="px-5 pb-3 pt-1.5">
            <View className="flex-row items-center gap-2.5 rounded-xl border border-ink-8 bg-white px-3.5 py-2.5">
              <Search size={18} color="rgba(27,42,42,0.5)" />
              <TextInput
                value={searchQuery}
                onChangeText={setSearchQuery}
                placeholder={placeholder}
                placeholderTextColor="rgba(27,42,42,0.4)"
                returnKeyType="search"
                className="flex-1 font-sans text-[14px] text-ink"
              />
            </View>
          </View>

          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingBottom: 14 }}
          >
            <Pressable
              onPress={() => toggleCategory(ALL_LIBRARY_FILTER_ID)}
              accessibilityRole="button"
              accessibilityLabel="Alles"
              accessibilityState={{ selected: activeCategoryIds.length === 0 }}
            >
              <Chip
                label="Alles"
                variant={activeCategoryIds.length === 0 ? 'filterActive' : 'outline'}
              />
            </Pressable>
            {categories.map((c: any) => (
              <Pressable
                key={c.id}
                onPress={() => toggleCategory(c.id)}
                accessibilityRole="button"
                accessibilityState={{ selected: activeCategoryIds.includes(c.id) }}
              >
                <Chip
                  label={c.label}
                  variant={activeCategoryIds.includes(c.id) ? 'filterActive' : 'outline'}
                />
              </Pressable>
            ))}
          </ScrollView>

          <SectionTitle>Voor jou · op basis van protocol</SectionTitle>
          <View className="flex-row flex-wrap justify-between gap-y-3 px-4">
            {filteredList.map((a: any) => (
              <Pressable
                key={a.id}
                onPress={() =>
                  router.push({
                    pathname: a.format === 'video' ? '/(tabs)/library/video/[id]' : '/(tabs)/library/article/[id]',
                    params: { id: a.id },
                  } as any)
                }
                style={{ width: '48.5%' }}
                className="overflow-hidden rounded-2xl border border-ink-8 bg-white p-2"
              >
                <LibraryThumbnail uri={a.heroImageUrl} format={a.format} />
                <View className="px-1 pb-1 pt-2">
                  <Text className="font-semi text-ink text-[14px]" numberOfLines={2}>
                    {a.title}
                  </Text>
                  <Text className="mt-0.5 text-[11px] text-ink-50">{a.durationLabel}</Text>
                </View>
              </Pressable>
            ))}
            {filteredList.length === 0 && (
              <Text className="px-2 py-8 text-center text-[14px] text-ink-50">
                Geen bibliotheekitems gevonden.
              </Text>
            )}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

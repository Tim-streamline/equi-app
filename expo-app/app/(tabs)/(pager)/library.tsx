import { useEffect, useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable, TextInput, Image } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search } from 'lucide-react-native';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryCategories,
  useLibraryItemCategories,
  useLibraryItems,
  useValue,
} from '@/db/hooks';
import { filterLibraryItems } from '@/lib/library-filter';

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const list = useLibraryItems();
  const itemCategories = useLibraryItemCategories();
  const placeholder = useValue('librarySearchPlaceholder') as string;
  const [activeCategoryIdsOverride, setActiveCategoryIdsOverride] = useState<string[] | null>(null);
  const { q, t } = useLocalSearchParams<{ q?: string; t?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  useEffect(() => {
    if (typeof q === 'string') {
      setSearchQuery(q);
      setActiveCategoryIdsOverride([]);
    }
  }, [q, t]);
  const defaultCategoryIds = useMemo(
    () => categories
      .filter((category) => category.isDefault)
      .map((category) => category.id as string),
    [categories],
  );
  const activeCategoryIds = activeCategoryIdsOverride ?? defaultCategoryIds;
  const filteredList = useMemo(
    () => filterLibraryItems(list, itemCategories, activeCategoryIds, searchQuery),
    [list, itemCategories, activeCategoryIds, searchQuery],
  );

  const toggleCategory = (categoryId: string) => {
    setActiveCategoryIdsOverride((current) => {
      const next = new Set(current ?? defaultCategoryIds);
      if (next.has(categoryId)) {
        next.delete(categoryId);
      } else {
        next.add(categoryId);
      }
      return [...next];
    });
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
          <View className="px-4 gap-2">
            {filteredList.map((a: any) => (
              <Pressable
                key={a.id}
                onPress={() =>
                  router.push({
                    pathname: a.format === 'video' ? '/(tabs)/library/video/[id]' : '/(tabs)/library/article/[id]',
                    params: { id: a.id },
                  } as any)
                }
                className="flex-row gap-3 rounded-2xl border border-ink-8 bg-white p-2.5"
              >
                <View
                  className="overflow-hidden rounded-xl"
                  style={{ width: 64, height: 64, backgroundColor: '#0D5C5B', alignItems: 'center', justifyContent: 'center' }}
                >
                  <Image
                    source={require('@/assets/images/logo-horse-white.png')}
                    style={{ width: 42, height: 42, opacity: 0.5, resizeMode: 'contain' }}
                  />
                </View>
                <View className="flex-1 justify-center">
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

import { useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, ScrollView, Pressable, TextInput, ActivityIndicator } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search, X } from 'lucide-react-native';
import { LibraryCard } from '@/components/library/LibraryCard';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { useLibraryBookmarks } from '@/hooks/useLibraryBookmarks';
import { type LibraryAccess } from '@/lib/library';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useLibraryCategories, useLibraryItemCategories, useLibraryItems, useValue } from '@/db/hooks';
import { filterLibraryItems, toggleLibraryCategory } from '@/lib/library-filter';

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const list = useLibraryItems();
  const { data: access, refresh: refreshAccess } = useLibraryResource<LibraryAccess>('/access');
  const bookmarks = useLibraryBookmarks();
  const itemCategories = useLibraryItemCategories();
  const placeholder = useValue('librarySearchPlaceholder') as string;
  const [activeCategoryIds, setActiveCategoryIds] = useState<string[]>([]);
  const [savedOnly, setSavedOnly] = useState(false);
  const { q, t } = useLocalSearchParams<{ q?: string; t?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const searchInput = useRef<TextInput>(null);
  useEffect(() => {
    if (typeof q === 'string') {
      setSearchQuery(q);
      setActiveCategoryIds([]);
      setSavedOnly(false);
    }
  }, [q, t]);
  const filteredList = useMemo(
    () => filterLibraryItems(list, itemCategories, activeCategoryIds, searchQuery, {
      savedOnly, savedIds: bookmarks.itemIds ?? [], access,
    }),
    [list, itemCategories, activeCategoryIds, searchQuery, savedOnly, bookmarks.itemIds, access],
  );
  const toggleCategory = (categoryId: string) => setActiveCategoryIds(current => toggleLibraryCategory(current, categoryId));
  const clearFilters = () => { setActiveCategoryIds([]); setSavedOnly(false); };
  const waiting = savedOnly && !bookmarks.itemIds;
  const filterError = savedOnly && bookmarks.error;
  const emptySaved = savedOnly && bookmarks.itemIds?.length === 0;
  return <View className="flex-1">
    <SafeAreaView edges={['top']} style={{ flex: 1 }}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: padBottom }}>
        <View className="px-5 pb-3 pt-1.5">
          <View className="flex-row items-center gap-2.5 rounded-xl border border-ink-8 bg-white pl-3.5 pr-1">
            <Search size={18} color="rgba(27,42,42,0.5)" />
            <TextInput ref={searchInput} value={searchQuery} onChangeText={setSearchQuery}
              accessibilityLabel="Zoek in de bibliotheek" placeholder={placeholder} placeholderTextColor="rgba(27,42,42,0.4)"
              returnKeyType="search" className="flex-1 py-3 font-sans text-[14px] text-ink" />
            {searchQuery.length > 0 && <Pressable accessibilityRole="button" accessibilityLabel="Zoekterm wissen"
              onPress={() => { setSearchQuery(''); searchInput.current?.focus(); }}
              style={{ width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }}><X size={18} color="#879392" /></Pressable>}
          </View>
        </View>
        <ScrollView horizontal keyboardShouldPersistTaps="handled" showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 20, gap: 8, paddingBottom: 12, alignItems: 'center' }}>
          <FilterChoice general label="Alles" selected={!savedOnly && activeCategoryIds.length === 0} onPress={clearFilters} />
          <FilterChoice general label="Opgeslagen" selected={savedOnly} onPress={() => setSavedOnly(value => !value)} />
          <View style={{ width: 1, height: 24, backgroundColor: '#CCD9D6', marginHorizontal: 2 }} />
          {categories.map(c => <FilterChoice key={c.id} label={c.label} selected={activeCategoryIds.includes(c.id)} onPress={() => toggleCategory(c.id)} />)}
        </ScrollView>
        {!!filterError && <Pressable accessibilityRole="button" onPress={() => { void bookmarks.refresh(); void refreshAccess(); }} className="mx-5 mb-3 py-2">
          <Text className="text-[13px] text-ink-70">{filterError} Tik om opnieuw te proberen.</Text>
        </Pressable>}
        {waiting ? (!filterError && <ActivityIndicator color="#127A79" />) : <>
          <Text className="mx-5 mb-3 text-[12px] text-ink-50">{filteredList.length} {filteredList.length === 1 ? 'item' : 'items'}</Text>
          <View className="flex-row flex-wrap justify-between gap-y-3 px-4">
            {filteredList.map(item => <LibraryCard key={item.id} item={item} access={access} showBookmark={savedOnly} />)}
          </View>
          {filteredList.length === 0 && <View className="px-7 py-8">
            <Text className="text-center font-semi text-[17px] text-ink">{emptySaved ? 'Nog niets opgeslagen' : 'Geen bibliotheekitems gevonden.'}</Text>
            {emptySaved && <>
              <Text className="mt-2 text-center text-[14px] leading-5 text-ink-50">Bewaar interessante artikelen, video&apos;s en andere content zodat je ze hier makkelijk terugvindt.</Text>
              <Pressable accessibilityRole="button" onPress={() => { clearFilters(); setSearchQuery(''); }} className="mt-4 py-2"><Text className="text-center font-semi text-teal-700">Bekijk de bibliotheek</Text></Pressable>
            </>}
          </View>}
        </>}
      </ScrollView>
    </SafeAreaView>
  </View>;
}

function FilterChoice({ label, selected, onPress, general = false }: { label: string; selected: boolean; onPress: () => void; general?: boolean }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ selected }} onPress={onPress} style={{ minHeight: 44, justifyContent: 'center', backgroundColor: general && !selected ? '#E9F1EE' : undefined, borderRadius: 24 }}>
    <Chip label={label} variant={selected ? 'filterActive' : 'outline'} />
  </Pressable>;
}

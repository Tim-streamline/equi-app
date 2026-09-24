import { useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, ScrollView, Pressable, TextInput, ActivityIndicator } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search, SlidersHorizontal, X } from 'lucide-react-native';
import { LibraryCard } from '@/components/library/LibraryCard';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { useLibraryBookmarks } from '@/hooks/useLibraryBookmarks';
import { libraryFormat, type LibraryAccess } from '@/lib/library';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useLibraryCategories, useLibraryItemCategories, useLibraryItems, useValue } from '@/db/hooks';
import { CREDIT_FILTERS, LIBRARY_FORMATS, filterLibraryItems, toggleLibraryCategory, type CreditFilter } from '@/lib/library-filter';

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const list = useLibraryItems();
  const { data: access, error: accessError, refresh: refreshAccess } = useLibraryResource<LibraryAccess>('/access');
  const bookmarks = useLibraryBookmarks();
  const itemCategories = useLibraryItemCategories();
  const placeholder = useValue('librarySearchPlaceholder') as string;
  const [activeCategoryIds, setActiveCategoryIds] = useState<string[]>([]);
  const [savedOnly, setSavedOnly] = useState(false);
  const [formats, setFormats] = useState<string[]>([]);
  const [credits, setCredits] = useState<CreditFilter[]>([]);
  const [accessibleOnly, setAccessibleOnly] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { q, t } = useLocalSearchParams<{ q?: string; t?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const searchInput = useRef<TextInput>(null);
  useEffect(() => {
    if (typeof q === 'string') {
      setSearchQuery(q);
      setActiveCategoryIds([]);
      setSavedOnly(false); setFormats([]); setCredits([]); setAccessibleOnly(false);
    }
  }, [q, t]);
  const filteredList = useMemo(
    () => filterLibraryItems(list, itemCategories, activeCategoryIds, searchQuery, {
      savedOnly, savedIds: bookmarks.itemIds ?? [], formats, credits, accessibleOnly, access,
    }),
    [list, itemCategories, activeCategoryIds, searchQuery, savedOnly, bookmarks.itemIds, formats, credits, accessibleOnly, access],
  );
  const toggleCategory = (categoryId: string) => setActiveCategoryIds(current => toggleLibraryCategory(current, categoryId));
  const toggleFormat = (format: string) => setFormats(current => current.includes(format) ? current.filter(value => value !== format) : [...current, format]);
  const toggleCredit = (credit: CreditFilter) => setCredits(current => current.includes(credit) ? current.filter(value => value !== credit) : [...current, credit]);
  const clearFilters = () => { setActiveCategoryIds([]); setFormats([]); setCredits([]); setAccessibleOnly(false); setSavedOnly(false); };
  const filterCount = activeCategoryIds.length + formats.length + credits.length + Number(accessibleOnly) + Number(savedOnly);
  const waiting = (savedOnly && !bookmarks.itemIds) || (accessibleOnly && !access);
  const filterError = (savedOnly && bookmarks.error) || (accessibleOnly && accessError);
  const emptySaved = savedOnly && bookmarks.itemIds?.length === 0;
  const chips = [
    ...categories.filter(c => activeCategoryIds.includes(c.id)).map(c => ({ key: `category-${c.id}`, label: c.label as string, remove: () => toggleCategory(c.id) })),
    ...formats.map(format => ({ key: `format-${format}`, label: libraryFormat(format), remove: () => toggleFormat(format) })),
    ...CREDIT_FILTERS.filter(credit => credits.includes(credit.id)).map(credit => ({ key: `credit-${credit.id}`, label: credit.label, remove: () => toggleCredit(credit.id) })),
    ...(accessibleOnly ? [{ key: 'accessible', label: 'Al ontgrendeld', remove: () => setAccessibleOnly(false) }] : []),
    ...(savedOnly ? [{ key: 'saved', label: 'Opgeslagen', remove: () => setSavedOnly(false) }] : []),
  ];
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
        <View className="flex-row items-center gap-2 px-5 pb-3">
          <FilterChoice label="Alles" selected={!savedOnly} onPress={() => setSavedOnly(false)} />
          <FilterChoice label="Opgeslagen" selected={savedOnly} onPress={() => setSavedOnly(true)} />
          <Pressable accessibilityRole="button" accessibilityLabel="Filters" accessibilityState={{ expanded: filtersOpen }}
            onPress={() => setFiltersOpen(open => !open)} className="ml-auto flex-row items-center gap-1.5 py-2">
            <SlidersHorizontal size={16} color="#127A79" /><Text className="font-semi text-[13px] text-teal-700">Filters{filterCount ? ` (${filterCount})` : ''}</Text>
          </Pressable>
        </View>
        <ScrollView horizontal keyboardShouldPersistTaps="handled" showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 20, gap: 8, paddingBottom: 12 }}>
          {categories.map(c => <FilterChoice key={c.id} label={c.label} selected={activeCategoryIds.includes(c.id)} onPress={() => toggleCategory(c.id)} />)}
        </ScrollView>
        {filtersOpen && <View className="mx-5 mb-3 gap-3 border-y border-ink-8 py-4">
          <Text className="font-semi text-[13px] text-ink">Contenttype</Text>
          <View className="flex-row flex-wrap gap-2">{LIBRARY_FORMATS.map(format =>
            <FilterChoice key={format} label={libraryFormat(format)} selected={formats.includes(format)} onPress={() => toggleFormat(format)} />)}</View>
          <Text className="font-semi text-[13px] text-ink">Credits</Text>
          <View className="flex-row flex-wrap gap-2">{CREDIT_FILTERS.map(credit =>
            <FilterChoice key={credit.id} label={credit.label} selected={credits.includes(credit.id)} onPress={() => toggleCredit(credit.id)} />)}</View>
          <View className="flex-row"><FilterChoice label="Al ontgrendeld" selected={accessibleOnly} onPress={() => setAccessibleOnly(value => !value)} /></View>
        </View>}
        {chips.length > 0 && <View className="mx-5 mb-4 flex-row flex-wrap items-center gap-2">
          {chips.map(chip => <Pressable key={chip.key} accessibilityRole="button" accessibilityLabel={`${chip.label} verwijderen`} onPress={chip.remove}
            className="flex-row items-center gap-1 rounded-full bg-mint-50 px-2.5 py-2">
            <Text className="text-[11px] text-teal-700">{chip.label}</Text><X size={12} color="#127A79" />
          </Pressable>)}
          <Pressable accessibilityRole="button" onPress={clearFilters} className="px-1 py-2"><Text className="font-semi text-[12px] text-teal-700">Wis filters</Text></Pressable>
        </View>}
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

function FilterChoice({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ selected }} onPress={onPress} style={{ minHeight: 40, justifyContent: 'center' }}>
    <Chip label={label} variant={selected ? 'filterActive' : 'outline'} />
  </Pressable>;
}

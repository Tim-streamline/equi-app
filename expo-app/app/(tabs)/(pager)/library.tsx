import { KeyboardScrollView as ScrollView, KeyboardTextInput as TextInput, KeyboardViewport } from '@/components/ui/KeyboardForm';
import { useEffect, useRef, useState } from 'react';
import { View, Text, Pressable, ActivityIndicator, FlatList, Modal, useWindowDimensions } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search, X, Check, SlidersHorizontal, ChevronLeft } from 'lucide-react-native';
import { LibraryCard } from '@/components/library/LibraryCard';
import { OfflineStrip } from '@/components/ui/OfflineStrip';
import { useHeaderAction } from '@/components/navigation/headerActions';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { useLibraryAccessRecords } from '@/hooks/useLibraryAccessRecords';
import { useLibraryBookmarks } from '@/hooks/useLibraryBookmarks';
import { libraryFormat, type LibraryAccess } from '@/lib/library';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useLibraryCategories, useLibraryFilterGroups, useLibraryViewingHistory, useLibraryItemCategories, useLibraryItems, useValue } from '@/db/hooks';
import { filterLibraryItems, toggleLibraryCategory, PERSONAL_FILTERS, LIBRARY_FORMATS, type PersonalFilter } from '@/lib/library-filter';

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const groups = useLibraryFilterGroups();
  const history = useLibraryViewingHistory();
  const list = useLibraryItems();
  const { data: liveAccess, error: accessError, refresh: refreshAccess } = useLibraryResource<LibraryAccess>('/access');
  const { grants, isLoading: accessLoading } = useLibraryAccessRecords();
  const access: LibraryAccess | null = accessLoading ? liveAccess : {
    hasPlus: liveAccess?.hasPlus ?? false, credits: liveAccess?.credits,
    unlockedIds: grants.filter(g => g.reason === 'unlocked').map(g => g.item_id),
    allowedIds: grants.map(g => g.item_id),
  };
  const bookmarks = useLibraryBookmarks();
  const itemCategories = useLibraryItemCategories();
  const placeholder = useValue('librarySearchPlaceholder') as string;
  const [activeCategoryIds, setActiveCategoryIds] = useState<string[]>([]);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { width, height, fontScale } = useWindowDimensions();
  // Large system text: two narrow columns break words mid-way, and Android wraps a
  // long placeholder onto a clipped second line.
  const columns = fontScale >= 1.5 ? 1 : 2;
  const [personal, setPersonal] = useState<PersonalFilter[]>([]);
  const [formats, setFormats] = useState<string[]>([]);
  const savedOnly = personal.includes('saved');
  const unlockedOnly = personal.includes('unlocked');
  const activeIds = activeCategoryIds.filter(id => categories.some(c => c.id === id));
  const categoryGroups = Object.fromEntries(categories.map(c => [c.id, c.groupId]));
  const { q, t } = useLocalSearchParams<{ q?: string; t?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const searchInput = useRef<TextInput>(null);
  useEffect(() => {
    if (typeof q === 'string') {
      setSearchQuery(q);
      setActiveCategoryIds([]);
      setPersonal([]); setFormats([]);
    }
  }, [q, t]);
  const filteredList = filterLibraryItems(list, itemCategories, activeIds, searchQuery, {
    personal, formats, savedIds: bookmarks.itemIds ?? [], access, categoryGroups,
    viewed: history.map(row => ({itemId: row.itemId as string, lastViewedAt: row.lastViewedAt as string | null})),
  });
  const toggleCategory = (categoryId: string) => setActiveCategoryIds(current => toggleLibraryCategory(current, categoryId));
  const togglePersonal = (id: PersonalFilter) => setPersonal(current => current.includes(id) ? current.filter(v => v !== id) : [...current, id]);
  const toggleFormat = (id: string) => setFormats(current => toggleLibraryCategory(current, id));
  const clearFilters = () => { setActiveCategoryIds([]); setPersonal([]); setFormats([]); };
  const waiting = (savedOnly && !bookmarks.itemIds) || (unlockedOnly && !access);
  const filterError = savedOnly ? bookmarks.error : unlockedOnly && !access ? accessError : null;
  const emptySaved = personal.length === 1 && savedOnly && bookmarks.itemIds?.length === 0;
  const activeCategories = categories.filter(category => activeIds.includes(category.id));
  const chips = [
    ...PERSONAL_FILTERS.filter(f => personal.includes(f.id)).map(f => ({key: `personal-${f.id}`, label: f.label, remove: () => togglePersonal(f.id)})),
    ...activeCategories.map(c => ({key: c.id, label: c.label as string, remove: () => toggleCategory(c.id)})),
    ...formats.map(f => ({key: `format-${f}`, label: libraryFormat(f), remove: () => toggleFormat(f)})),
  ];
  const formatChoices = LIBRARY_FORMATS.filter(f => ['article', 'video', 'audio'].includes(f) || list.some(item => item.format === f));
  const closeFilters = () => setFiltersOpen(false);
  const resultsLabel = waiting || filterError ? 'Terug naar bibliotheek'
    : `Toon ${filteredList.length} ${filteredList.length === 1 ? 'item' : 'items'}`;
  // The credit balance lives in the shared header, only while Bibliotheek is visible.
  const credits = liveAccess?.credits;
  useHeaderAction('library', credits == null ? null : (
    <Pressable accessibilityRole="button" accessibilityLabel={`Je credits: ${credits}. Credits bekijken`}
      onPress={() => router.push('/(tabs)/account/credits')} className="h-11 justify-center">
      <View className="rounded-pill bg-[#E7F5F3] px-3 py-1">
        <Text accessibilityLiveRegion="polite" maxFontSizeMultiplier={1.3} className="font-semi text-[14px] leading-[18px] text-teal-700">
          {credits} {credits === 1 ? 'credit' : 'credits'}
        </Text>
      </View>
    </Pressable>
  ));
  return <View className="flex-1">
    <View style={{ flex: 1 }}>
      {!!accessError && !liveAccess && <OfflineStrip message={accessError} onRetry={() => void refreshAccess()} />}
      <KeyboardViewport style={{ flex: 1 }}>
        {/* Search stays under the header while the cards scroll. */}
        <View className="px-5 pb-3 pt-4">
          <View className="flex-row items-center gap-3">
            <View className="flex-1 flex-row items-center gap-2.5 rounded-pill border border-ink-8 bg-white pl-3.5 pr-1" style={{ minWidth: 0 }}>
              <Search size={18} color="rgba(27,42,42,0.5)" />
              <TextInput ref={searchInput} value={searchQuery} onChangeText={setSearchQuery}
                accessibilityLabel="Zoek in de bibliotheek" placeholder={fontScale >= 1.3 ? 'Zoeken' : placeholder} placeholderTextColor="rgba(27,42,42,0.55)"
                returnKeyType="search" maxFontSizeMultiplier={1.4} className="flex-1 py-3 font-sans text-[16px] text-ink" />
              {searchQuery.length > 0 && <Pressable accessibilityRole="button" accessibilityLabel="Zoekterm wissen"
                onPress={() => { setSearchQuery(''); searchInput.current?.focus(); }}
                style={{ width: 44, height: 44, alignItems: 'center', justifyContent: 'center' }}><X size={18} color="#879392" /></Pressable>}
            </View>
            <Pressable accessibilityRole="button" accessibilityLabel="Filters openen"
              accessibilityHint={`${chips.length} actieve filters`}
              accessibilityState={{ expanded: filtersOpen }} aria-expanded={filtersOpen}
              onPress={() => { searchInput.current?.blur(); setFiltersOpen(true); }}
              className="h-12 w-12 items-center justify-center rounded-pill bg-teal-700">
              <SlidersHorizontal size={22} color="#FBF8F3" />
              {chips.length > 0 && <View className="absolute -right-1 -top-1 min-w-5 items-center rounded-pill border-2 border-canvas bg-mint-500 px-1">
                <Text className="font-bold text-[11px] text-teal-900" maxFontSizeMultiplier={1.2}>{chips.length}</Text>
              </View>}
            </Pressable>
          </View>
        </View>
        {/* Virtualized grid: only cards near the viewport render, which keeps long libraries smooth on older phones. */}
        <FlatList
          key={columns}
          data={waiting ? [] : filteredList}
          keyExtractor={item => String(item.id)}
          numColumns={columns}
          keyboardShouldPersistTaps="handled"
          columnWrapperStyle={columns > 1 ? { justifyContent: 'space-between', paddingHorizontal: 16 } : undefined}
          ItemSeparatorComponent={CardGap}
          contentContainerStyle={columns > 1 ? { paddingBottom: padBottom } : { paddingHorizontal: 16, paddingBottom: padBottom }}
          initialNumToRender={6}
          windowSize={7}
          ListHeaderComponent={<>
            <View className="flex-row flex-wrap items-center gap-2 px-5 pb-3">
              {chips.map(chip => <Pressable key={chip.key} accessibilityRole="button"
                accessibilityLabel={`Verwijder filter: ${chip.label}`} onPress={chip.remove}
                className="min-h-11 flex-row items-center gap-2 rounded-pill bg-[#E7F5F3] px-3.5" style={{ maxWidth: '100%' }}>
                <Text className="shrink font-semi text-[14px] text-teal-700">{chip.label}</Text>
                <X size={16} color="#127A79" />
              </Pressable>)}
              {chips.length > 0 && <Pressable accessibilityRole="button" accessibilityLabel="Alle filters wissen"
                onPress={clearFilters} className="min-h-11 justify-center px-2">
                <Text className="font-semi text-[14px] text-ink-70 underline">Wis alles</Text>
              </Pressable>}
            </View>
            {!!filterError && <Pressable accessibilityRole="button" onPress={() => { void bookmarks.refresh(); void refreshAccess(); }} className="mx-5 mb-3 py-2">
              <Text className="text-[13px] text-ink-70">{filterError} Tik om opnieuw te proberen.</Text>
            </Pressable>}
            {waiting ? (!filterError && <ActivityIndicator color="#127A79" />)
              : <Text className="mx-5 mb-3 text-[12px] text-ink-70">{filteredList.length} {filteredList.length === 1 ? 'item' : 'items'}</Text>}
          </>}
          ListEmptyComponent={waiting ? null : <View className="px-7 py-8">
            <Text className="text-center font-semi text-[17px] text-ink">{emptySaved ? 'Nog niets opgeslagen' : 'Geen bibliotheekitems gevonden.'}</Text>
            {emptySaved && <>
              <Text className="mt-2 text-center text-[14px] leading-5 text-ink-70">Bewaar interessante artikelen, video&apos;s en andere content zodat je ze hier makkelijk terugvindt.</Text>
              <Pressable accessibilityRole="button" onPress={() => { clearFilters(); setSearchQuery(''); }} className="mt-4 py-2"><Text className="text-center font-semi text-teal-700">Bekijk de bibliotheek</Text></Pressable>
            </>}
          </View>}
          renderItem={({ item }) => <LibraryCard item={item} access={access} showBookmark={savedOnly} wide={columns === 1} />}
        />
      </KeyboardViewport>
    </View>
    {filtersOpen && <Modal transparent animationType="fade" statusBarTranslucent navigationBarTranslucent onRequestClose={closeFilters}>
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: 'rgba(27,42,42,0.35)' }}>
        <SafeAreaView accessibilityViewIsModal accessibilityLabel="Filters" style={{ width: '100%', maxWidth: width >= 768 ? 560 : undefined,
          height: width >= 768 ? Math.min(680, height - 64) : '100%', backgroundColor: '#FBF8F3', borderRadius: width >= 768 ? 24 : 0, overflow: 'hidden' }}>
          <View className="flex-row items-center gap-2 border-b border-ink-8 px-4 py-3">
            <Pressable accessibilityRole="button" accessibilityLabel="Filters sluiten" onPress={closeFilters} className="h-11 w-11 items-center justify-center">
              <ChevronLeft size={26} color="#127A79" />
            </Pressable>
            <Text accessibilityRole="header" className="flex-1 font-bold text-[23px] text-ink">Filters</Text>
            <Pressable accessibilityRole="button" accessibilityLabel="Alle filters wissen" onPress={clearFilters}
              className="min-h-11 justify-center px-2">
              <Text className="font-semi text-[16px] text-teal-700">Wis alles</Text>
            </Pressable>
          </View>
          <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 24 }}>
            <Text accessibilityRole="header" className="mb-3 font-bold text-[20px] text-teal-900">Persoonlijk</Text>
            <View className="mb-3 flex-row flex-wrap gap-2">
              {PERSONAL_FILTERS.map(filter => <FilterChoice key={filter.id} label={filter.label} selected={personal.includes(filter.id)} onPress={() => togglePersonal(filter.id)} />)}
            </View>
            <Text className="mb-6 text-[13px] text-ink-70">Recent bekeken: de afgelopen 30 dagen.</Text>
            {groups.map(group => <View key={group.id} className="mb-6">
              <Text accessibilityRole="header" className="mb-3 font-bold text-[20px] text-teal-900">{group.label}</Text>
              <View className="flex-row flex-wrap gap-2">
                {categories.filter(c => c.groupId === group.id).map(category => <FilterChoice key={category.id} label={category.label}
                  selected={activeIds.includes(category.id)} onPress={() => toggleCategory(category.id)} />)}
              </View>
            </View>)}
            <Text accessibilityRole="header" className="mb-3 font-bold text-[20px] text-teal-900">Soort</Text>
            <View className="flex-row flex-wrap gap-2">
              {formatChoices.map(format => <FilterChoice key={format} label={libraryFormat(format)} selected={formats.includes(format)} onPress={() => toggleFormat(format)} />)}
            </View>
          </ScrollView>
          <View className="border-t border-ink-8 px-5 py-4">
            <Pressable accessibilityRole="button" accessibilityLabel={resultsLabel} onPress={closeFilters}
              className="min-h-14 items-center justify-center rounded-pill bg-mint-500 px-5 py-3">
              <Text className="font-bold text-[18px] text-teal-900">{resultsLabel}</Text>
            </Pressable>
          </View>
        </SafeAreaView>
      </View>
    </Modal>}
  </View>;
}

function FilterChoice({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  return <Pressable accessibilityRole="checkbox" accessibilityLabel={label} accessibilityState={{ checked: selected }} aria-checked={selected} onPress={onPress}
    className={`min-h-11 flex-row items-center gap-2 rounded-pill px-4 py-2.5 ${selected ? 'bg-teal-700' : 'bg-[#E7F5F3]'}`} style={{ maxWidth: '100%' }}>
    {selected && <Check size={18} color="#FBF8F3" />}
    <Text className={`shrink font-semi text-[16px] ${selected ? 'text-canvas' : 'text-teal-900'}`}>{label}</Text>
  </Pressable>;
}

function CardGap() {
  return <View style={{ height: 12 }} />;
}

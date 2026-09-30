import { KeyboardScrollView as ScrollView, KeyboardTextInput as TextInput, KeyboardViewport } from '@/components/ui/KeyboardForm';
import { useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, Pressable, ActivityIndicator, Modal, useWindowDimensions } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search, X, ChevronDown, Check } from 'lucide-react-native';
import { LibraryCard } from '@/components/library/LibraryCard';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { useLibraryBookmarks } from '@/hooks/useLibraryBookmarks';
import { type LibraryAccess } from '@/lib/library';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useLibraryCategories, useLibraryItemCategories, useLibraryItems, useValue } from '@/db/hooks';
import { filterLibraryItems, toggleLibraryCategory } from '@/lib/library-filter';

const libraryScopes = [
  { value: 'all', label: 'Alles' },
  { value: 'mine', label: 'Mijn items' },
  { value: 'saved', label: 'Opgeslagen' },
  { value: 'free', label: 'Gratis' },
] as const;
type LibraryScope = typeof libraryScopes[number]['value'];

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const list = useLibraryItems();
  const { data: access, error: accessError, refresh: refreshAccess } = useLibraryResource<LibraryAccess>('/access');
  const bookmarks = useLibraryBookmarks();
  const itemCategories = useLibraryItemCategories();
  const placeholder = useValue('librarySearchPlaceholder') as string;
  const [activeCategoryIds, setActiveCategoryIds] = useState<string[]>([]);
  const [scope, setScope] = useState<LibraryScope>('all');
  const savedOnly = scope === 'saved';
  const accessibleOnly = scope === 'mine';
  const { q, t } = useLocalSearchParams<{ q?: string; t?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const searchInput = useRef<TextInput>(null);
  useEffect(() => {
    if (typeof q === 'string') {
      setSearchQuery(q);
      setActiveCategoryIds([]);
      setScope('all');
    }
  }, [q, t]);
  const filteredList = useMemo(
    () => filterLibraryItems(list, itemCategories, activeCategoryIds, searchQuery, {
      credits: scope === 'free' ? ['free'] : undefined,
      excludeFree: accessibleOnly,
      savedOnly, accessibleOnly, savedIds: bookmarks.itemIds ?? [], access,
    }),
    [list, itemCategories, activeCategoryIds, searchQuery, scope, savedOnly, accessibleOnly, bookmarks.itemIds, access],
  );
  const toggleCategory = (categoryId: string) => setActiveCategoryIds(current => toggleLibraryCategory(current, categoryId));
  const clearFilters = () => { setActiveCategoryIds([]); setScope('all'); };
  const waiting = (savedOnly && !bookmarks.itemIds) || (accessibleOnly && !access);
  const filterError = savedOnly ? bookmarks.error : accessibleOnly ? accessError : null;
  const emptySaved = savedOnly && bookmarks.itemIds?.length === 0;
  return <View className="flex-1">
    <SafeAreaView edges={['top']} style={{ flex: 1 }}>
      <KeyboardViewport style={{ flex: 1 }}><ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: padBottom }}>
        <View className="px-5 pb-3 pt-1.5">
          <View className="mb-3 flex-row items-center justify-between">
            <Text accessibilityRole="header" className="font-bold text-[22px] text-ink">Bibliotheek</Text>
            {access ? <Text accessibilityLiveRegion="polite" className="font-semi text-[15px] text-teal-700">Je credits: {access.credits}</Text>
              : accessError ? <Pressable accessibilityRole="button" onPress={() => void refreshAccess()}><Text className="text-[13px] text-teal-700">Saldo opnieuw laden</Text></Pressable>
              : <ActivityIndicator accessibilityLabel="Credits laden" color="#127A79" />}
          </View>
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
        <View className="flex-row items-center pb-3 pl-5">
          <LibraryScopeDropdown value={scope} onChange={setScope} />
          <View style={{ width: 1, height: 24, backgroundColor: '#CCD9D6', marginHorizontal: 10 }} />
          <ScrollView horizontal keyboardShouldPersistTaps="handled" showsHorizontalScrollIndicator={false}
            style={{ flex: 1 }} contentContainerStyle={{ paddingRight: 20, gap: 8, alignItems: 'center' }}>
            {categories.map(c => <FilterChoice key={c.id} label={c.label} selected={activeCategoryIds.includes(c.id)} onPress={() => toggleCategory(c.id)} />)}
          </ScrollView>
        </View>
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
      </ScrollView></KeyboardViewport>
    </SafeAreaView>
  </View>;
}

function FilterChoice({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ selected }} onPress={onPress} style={{ minHeight: 44, justifyContent: 'center' }}>
    <Chip label={label} variant={selected ? 'filterActive' : 'outline'} />
  </Pressable>;
}

function LibraryScopeDropdown({ value, onChange }: { value: LibraryScope; onChange: (value: LibraryScope) => void }) {
  const trigger = useRef<View>(null);
  const [anchor, setAnchor] = useState<{ x: number; y: number } | null>(null);
  const { width, height } = useWindowDimensions();
  const label = libraryScopes.find(option => option.value === value)!.label;
  const close = () => { setAnchor(null); };
  return <>
    <Pressable ref={trigger} accessibilityRole="button" accessibilityLabel={`Bibliotheekfilter: ${label}`}
      accessibilityHint="Kies Alles, Mijn items, Opgeslagen of Gratis" accessibilityState={{ expanded: !!anchor }}
      onPress={() => trigger.current?.measureInWindow((x, y, _width, triggerHeight) => setAnchor({ x, y: y + triggerHeight + 4 }))}
      className="min-h-11 flex-row items-center gap-2 rounded-xl border border-teal-700 bg-white px-3">
      <Text className="font-semi text-[13px] text-teal-700">{label}</Text>
      <ChevronDown size={16} color="#127A79" />
    </Pressable>
    {anchor && <Modal transparent animationType="fade" statusBarTranslucent navigationBarTranslucent onRequestClose={close}>
      <View style={{ flex: 1 }}>
        <Pressable accessibilityRole="button" accessibilityLabel="Bibliotheekfilter sluiten" onPress={close}
          style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }} />
        <View accessibilityViewIsModal style={{ position: 'absolute', left: Math.max(12, Math.min(anchor.x, width - 212)), top: Math.max(12, Math.min(anchor.y, height - (libraryScopes.length * 48 + 32))), width: 200,
          borderRadius: 12, borderWidth: 1, borderColor: '#CCD9D6', padding: 6, backgroundColor: '#FFFFFF', elevation: 8,
          shadowColor: '#1B2A2A', shadowOpacity: 0.15, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } }}>
          {libraryScopes.map(option => <Pressable key={option.value} accessibilityRole="radio" accessibilityLabel={option.label}
            accessibilityState={{ checked: option.value === value }}
            onPress={() => { onChange(option.value); close(); }}
            style={{ minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 12, borderRadius: 8,
              backgroundColor: option.value === value ? '#EFF6F3' : 'transparent' }}>
            <Text className="font-semi text-[14px] text-teal-700">{option.label}</Text>
            {option.value === value && <Check size={16} color="#127A79" />}
          </Pressable>)}
        </View>
      </View>
    </Modal>}
  </>;
}

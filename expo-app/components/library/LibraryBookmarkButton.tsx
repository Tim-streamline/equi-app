import { Alert, Pressable } from 'react-native';
import { Bookmark } from 'lucide-react-native';
import { useLibraryBookmarks } from '@/hooks/useLibraryBookmarks';

export function LibraryBookmarkButton({ itemId }: { itemId: string }) {
  const { itemIds, pendingIds, error, refresh, toggle } = useLibraryBookmarks();
  const selected = itemIds?.includes(itemId) ?? false;
  const busy = pendingIds.includes(itemId);
  const disabled = busy || (!itemIds && !error);
  async function onPress() {
    if (!itemIds) { void refresh(); return; }
    try { await toggle(itemId); }
    catch (error) { Alert.alert('Bewaren mislukt', error instanceof Error ? error.message : 'Probeer opnieuw.'); }
  }
  return <Pressable accessibilityRole="button" accessibilityLabel={selected ? 'Uit bewaard verwijderen' : 'Bibliotheekitem bewaren'}
    accessibilityState={{ selected, busy, disabled }} disabled={disabled}
    onPress={(event) => { event.stopPropagation(); void onPress(); }}
    style={{ width: 44, height: 44, alignItems: 'center', justifyContent: 'center', opacity: busy ? 0.5 : 1 }}>
    <Bookmark size={20} color={selected ? '#127A79' : '#1B2A2A'} fill={selected ? '#BDE8DD' : 'transparent'} />
  </Pressable>;
}

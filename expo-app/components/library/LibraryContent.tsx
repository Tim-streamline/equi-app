import { useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Text, View } from 'react-native';
import { router } from 'expo-router';
import { libraryRequest, useLibraryResource } from '@/hooks/useLibraryResource';
import type { LibraryAccess, LibraryCardItem } from '@/lib/library';
import { MarkdownBody } from './MarkdownBody';
import { SectionTitle } from '@/components/ui/SectionTitle';

type Content = { item: LibraryCardItem; access: LibraryAccess; canRead: boolean; body: string | null; chapters: { id: string; title: string; startLabel: string }[] };

export function LibraryContent({ itemId }: { itemId: string }) {
  const { data, error, refresh, update } = useLibraryResource<Content>(`/${encodeURIComponent(itemId)}`);
  const pending = useRef(false);
  const [busy, setBusy] = useState(false);
  async function unlock() {
    if (!data || pending.current) return;
    pending.current = true; setBusy(true);
    try {
      update(await libraryRequest<Content>(`/${encodeURIComponent(itemId)}/unlock`, 'POST', undefined, { credits: data.item.creditCost ?? 0 }));
    } catch (error) {
      Alert.alert('Ontgrendelen mislukt', error instanceof Error ? error.message : 'Probeer opnieuw.');
    } finally { pending.current = false; setBusy(false); }
  }
  if (!data) return <View className="p-5">{error
    ? <Pressable accessibilityRole="button" onPress={() => void refresh()}><Text className="text-ink-70">{error} Tik om opnieuw te proberen.</Text></Pressable>
    : <ActivityIndicator color="#127A79" />}</View>;
  if (!data.canRead) return <View className="mx-5 mt-5 gap-3 rounded-2xl bg-mint-50 p-5">
    <Text className="text-[14px] leading-5 text-ink-70">{data.item.description}</Text>
    <Text className="font-semi text-ink">{data.item.isPlus ? 'Dit item is beschikbaar met Plus.' : `Ontgrendel voor ${data.item.creditCost} ${data.item.creditCost === 1 ? 'credit' : 'credits'}. Je hebt ${data.access.credits ?? 0} credits.`}</Text>
    {data.item.isPlus ? <Pressable accessibilityRole="button" onPress={() => router.push('/plus')}><Text className="font-semi text-teal-700">Bekijk Plus →</Text></Pressable>
      : <Pressable accessibilityRole="button" disabled={busy} onPress={() => Alert.alert('Item ontgrendelen', `Wil je ${data.item.creditCost} ${data.item.creditCost === 1 ? 'credit' : 'credits'} gebruiken om dit item te ontgrendelen?`, [
        { text: 'Annuleren', style: 'cancel' }, { text: 'Ontgrendelen', onPress: () => void unlock() },
      ])}><Text className="font-semi text-teal-700">{busy ? 'Bezig…' : 'Ontgrendelen'}</Text></Pressable>}
  </View>;
  return <>
    {!!data.body && <View className="w-full px-5 pt-5"><MarkdownBody markdown={data.body} /></View>}
    {data.chapters.length > 0 && <>
      <SectionTitle>Hoofdstukken</SectionTitle>
      <View className="px-4">{data.chapters.map((chapter, index) => <View key={chapter.id} className="flex-row items-center gap-3 rounded-xl p-3.5">
        <Text className="font-bold text-ink-50">{String(index + 1).padStart(2, '0')}</Text>
        <Text className="flex-1 text-[14px] text-ink">{chapter.title}</Text><Text className="text-[12px] text-ink-50">{chapter.startLabel}</Text>
      </View>)}</View>
    </>}
  </>;
}

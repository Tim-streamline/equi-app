import { TemporaryCreditButton } from '@/components/credits/TemporaryCreditButton';
import { useRef, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { router } from 'expo-router';
import { libraryRequest, useLibraryResource } from '@/hooks/useLibraryResource';
import { libraryMetadata, type LibraryAccess, type LibraryCardItem } from '@/lib/library';
import { MarkdownBody } from './MarkdownBody';
import { LibraryAttachments, type LibraryAttachment } from './LibraryAttachments';
import { LibraryThumbnail } from './LibraryThumbnail';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { Button } from '@/components/ui/Button';

type Content = { item: LibraryCardItem; access: LibraryAccess; canRead: boolean; body: string | null; attachments: LibraryAttachment[]; chapters: { id: string; title: string; startLabel: string }[] };

export function LibraryContent({ itemId, preview }: { itemId: string; preview?: LibraryCardItem }) {
  const { data, error, refresh, update } = useLibraryResource<Content>(`/${encodeURIComponent(itemId)}`);
  const pending = useRef(false);
  const [busy, setBusy] = useState(false);
  const [confirmedCost, setConfirmedCost] = useState<number | null>(null);
  const [purchaseError, setPurchaseError] = useState('');
  const item = { ...preview, ...data?.item, id: itemId };
  const cost = data?.item.creditCost ?? 0;
  const balance = data?.access.credits ?? 0;
  const insufficient = balance < cost;
  const credits = (value: number) => `${value} ${value === 1 ? 'credit' : 'credits'}`;

  async function unlock() {
    if (!data || data.canRead || data.item.isPlus || insufficient || pending.current || confirmedCost === null) return;
    pending.current = true; setBusy(true); setPurchaseError('');
    try {
      update(await libraryRequest<Content>(`/${encodeURIComponent(itemId)}/unlock`, 'POST', undefined, { credits: confirmedCost }));
      setConfirmedCost(null);
    } catch (cause) {
      setPurchaseError(cause instanceof Error ? cause.message : 'Ontgrendelen is niet gelukt. Probeer opnieuw.');
      setConfirmedCost(null);
      await refresh();
    } finally { pending.current = false; setBusy(false); }
  }

  return <>
    <View className="px-5 pt-5">
      {!data?.canRead && <View className="mb-5"><LibraryThumbnail uri={item.heroImageUrl} format={item.format} style={{ maxWidth: 480, alignSelf: 'center' }} /></View>}
      <Eyebrow className="mb-2">{libraryMetadata(item)}</Eyebrow>
      <Text className="font-bold text-ink mb-4" style={{ fontSize: 28, lineHeight: 32 }}>{item.title}</Text>
      {!data?.canRead && !!item.description && <Text className="text-[16px] leading-6 text-ink-70">{item.description}</Text>}
    </View>
    {!data ? <View className="p-5 gap-3">{error
      ? <><Text accessibilityRole="alert" className="text-ink-70">{error}</Text><Button title="Opnieuw proberen" variant="ghost" onPress={() => void refresh()} /></>
      : <ActivityIndicator accessibilityLabel="Toegang controleren" color="#127A79" />}</View>
      : !data.canRead && data.item.isPlus ? <View className="mx-5 mt-5 gap-3 rounded-2xl bg-mint-50 p-5">
        <Text className="font-bold text-[20px] text-ink">Alleen voor Plus</Text>
        <Text className="text-[15px] text-ink-70">Dit item is beschikbaar met actief Plus. Je kunt het niet met credits ontgrendelen.</Text>
        <Button title="Bekijk Plus" onPress={() => router.push('/(tabs)/(pager)/protocol')} />
      </View> : !data.canRead ? <View className="mx-5 mt-5 gap-3 rounded-2xl bg-mint-50 p-5">
        <Text className="font-bold text-[20px] text-ink">Ontgrendel dit item</Text>
          <Text className="text-[15px] text-ink-70">{`Dit item kost ${credits(cost)}. Je hebt ${credits(balance)}.`}</Text>
          {insufficient && <Text className="text-[14px] text-ink-70">{`Je hebt nog ${credits(cost - balance)} nodig om dit item te ontgrendelen.`}</Text>}
          {insufficient && <TemporaryCreditButton disabled={busy} onAdded={() => refresh()} />}
          {confirmedCost !== null ? <>
            <Text className="text-[15px] text-ink">{`${credits(confirmedCost)} gebruiken?`}</Text>
            <Text className="text-[15px] text-ink">{`Daarna heb je nog ${credits(Math.max(0, balance - confirmedCost))}.`}</Text>
            <Text className="text-[14px] text-ink-70">Dit item blijft daarna ontgrendeld in je bibliotheek.</Text>
            <Button title={busy ? 'Ontgrendelen…' : `Bevestig: ${credits(confirmedCost)} gebruiken`} disabled={busy || insufficient} onPress={() => void unlock()} />
            <Button title="Annuleren" variant="text" disabled={busy} onPress={() => setConfirmedCost(null)} />
          </> : <Button title={`Ontgrendel voor ${credits(cost)}`} disabled={busy || insufficient} onPress={() => { setPurchaseError(''); setConfirmedCost(cost); }} />}
      </View> : <>
        {!!data.body && <View className="w-full px-5 pt-5"><MarkdownBody markdown={data.body} /></View>}
        {data.chapters.length > 0 && <>
          <SectionTitle>Hoofdstukken</SectionTitle>
          <View className="px-4">{data.chapters.map((chapter, index) => <View key={chapter.id} className="flex-row items-center gap-3 rounded-xl p-3.5">
            <Text className="font-bold text-ink-50">{String(index + 1).padStart(2, '0')}</Text>
            <Text className="flex-1 text-[14px] text-ink">{chapter.title}</Text><Text className="text-[12px] text-ink-50">{chapter.startLabel}</Text>
          </View>)}</View>
        </>}
        <LibraryAttachments itemId={itemId} attachments={data.attachments ?? []} />
      </>}
    {!!purchaseError && <Text accessibilityRole="alert" className="mx-5 mt-3 text-red-700">{purchaseError}</Text>}
  </>;
}

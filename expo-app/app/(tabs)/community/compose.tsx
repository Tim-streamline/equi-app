import { useEffect, useRef, useState } from 'react';
import { Alert, Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useNavigation, usePreventRemove } from '@react-navigation/native';
import { SafeAreaView } from 'react-native-safe-area-context';
import * as ImagePicker from 'expo-image-picker';
import { Play } from 'lucide-react-native';
import { useDb } from '@/db/provider';
import { communityRequest, useCommunityResource } from '@/hooks/useCommunity';
import type { CommunityFeedData, CommunityThreadData } from '@/lib/community';
import { Action, MembershipNotice, ResourceState } from '@/components/community/Common';
import { MediaGallery } from '@/components/community/MediaGallery';
import { SubHeader } from '@/components/ui/SubHeader';

export default function CommunityCompose() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const { isLoggedIn } = useDb();
  const navigation = useNavigation();
  const { data, error, refresh } = useCommunityResource<CommunityFeedData>('/api/community');
  const thread = useCommunityResource<CommunityThreadData>(`/api/community/posts/${id}`, !!id);
  const original = thread.data?.post ?? null;
  const initialized = useRef(false);
  const [body, setBody] = useState('');
  const [category, setCategory] = useState('');
  const [tags, setTags] = useState<string[]>([]);
  const [assets, setAssets] = useState<ImagePicker.ImagePickerAsset[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  const [dirty, setDirty] = useState(false);
  const [sending, setSending] = useState(false);
  const [completed, setCompleted] = useState(false);
  const newPostId = useRef<string | null>(null);
  useEffect(() => {
    if (!original || initialized.current) return;
    initialized.current = true;
    setBody(original.body); setCategory(original.category?.id ?? ''); setTags(original.tags.map(tag => tag.id));
  }, [original]);
  usePreventRemove((dirty || sending) && !completed, ({ data: event }) => {
    if (sending) { Alert.alert('Upload bezig', 'Wacht tot je bericht is verstuurd.'); return; }
    Alert.alert('Wijzigingen weggooien?', 'Je niet-geplaatste tekst en bijlagen worden niet opgeslagen.', [
      { text: 'Verder schrijven', style: 'cancel' }, { text: 'Weggooien', style: 'destructive', onPress: () => navigation.dispatch(event.action) },
    ]);
  });
  useEffect(() => {
    if (!completed || !newPostId.current) return;
    if (id && router.canGoBack()) router.back();
    else router.replace({ pathname: '/(tabs)/community/thread/[id]', params: { id: newPostId.current } });
  }, [completed, id]);
  const existing = original?.media.filter(media => !removed.includes(media.id)) ?? [];
  const pick = async () => {
    try {
      const slots = 4 - existing.length - assets.length;
      if (slots <= 0) return;
      const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images', 'videos'], allowsMultipleSelection: true, selectionLimit: slots, quality: 0.9, preferredAssetRepresentationMode: ImagePicker.UIImagePickerPreferredAssetRepresentationMode.Compatible });
      if (result.canceled) return;
      if (result.assets.some(asset => (asset.fileSize ?? 0) > 20 * 1024 * 1024)) { Alert.alert('Bijlage te groot', 'Kies foto’s of video’s van maximaal 20 MB per bestand.'); return; }
      setAssets(current => [...current, ...result.assets].slice(0, 4 - existing.length)); setDirty(true);
    } catch (e) { Alert.alert('Bijlage niet beschikbaar', e instanceof Error ? e.message : 'Probeer opnieuw.'); }
  };
  const submit = async () => {
    if (sending || !body.trim()) return;
    setSending(true);
    try {
      const form = new FormData();
      form.append('body', body.trim()); form.append('category_id', category); form.append('tags', JSON.stringify(tags));
      if (id) form.append('_method', 'PATCH');
      removed.forEach(mediaId => form.append('remove_media[]', mediaId));
      assets.forEach((asset, index) => form.append('media[]', { uri: asset.uri, name: asset.fileName ?? `attachment-${index}.${asset.type === 'video' ? 'mp4' : 'jpg'}`, type: asset.mimeType ?? (asset.type === 'video' ? 'video/mp4' : 'image/jpeg') } as unknown as Blob));
      const result = await communityRequest<{ id: string }>(`/api/community/posts${id ? `/${id}` : ''}`, 'POST', form);
      newPostId.current = result.id; setCompleted(true); setDirty(false);
    } catch (e) { Alert.alert('Bericht niet opgeslagen', e instanceof Error ? e.message : 'Probeer opnieuw. Je tekst blijft hier staan.'); }
    finally { setSending(false); }
  };
  const back = () => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/community');
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title={id ? 'Bericht bewerken' : 'Nieuw bericht'} onBack={back} />
    {!isLoggedIn ? <View className="p-5"><Action label="Inloggen" onPress={() => router.push('/onboarding/welcome')} /></View>
      : !data || (id && !original) ? <ResourceState error={error || thread.error} retry={() => { void refresh(); if (id) void thread.refresh(); }} />
      : !data.canParticipate ? <MembershipNotice /> : original && (!original.owned || original.locked) ? <Text className="p-5 text-ink">Je kunt dit bericht niet bewerken.</Text> :
        <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 20, gap: 18 }}>
            <Text className="text-[15px] leading-5 text-ink-70">Deel je ervaring of stel een vraag aan de community.</Text>
            <TextInput accessibilityLabel="Je bericht" value={body} editable={!sending} onChangeText={value => { setBody(value); setDirty(true); }} multiline maxLength={10000} textAlignVertical="top" placeholder="Wat wil je delen?" className="min-h-48 rounded-2xl border border-ink-8 bg-white p-4 text-[16px] leading-6 text-ink" />
            <Text className="font-semi text-[16px] text-ink">Onderwerp</Text>
            <View className="flex-row flex-wrap gap-2">{[{ id: '', label: 'Algemeen', slug: '' }, ...data.categories].map(item => <Action key={item.id} label={item.label} primary={category === item.id} disabled={sending} onPress={() => { setCategory(item.id); setDirty(true); }} />)}</View>
            {data.categories.find(item => item.id === category)?.slug === 'vraag-shelley' && <Text className="rounded-xl bg-mint-50 p-4 text-teal-700">Shelley reageert wanneer dat lukt. Een antwoord of reactietijd is niet gegarandeerd.</Text>}
            {!!data.tags.length && <><Text className="font-semi text-[16px] text-ink">Labels · maximaal 5</Text><View className="flex-row flex-wrap gap-2">{data.tags.map(tag => <Action key={tag.id} label={tag.label} primary={tags.includes(tag.id)} disabled={sending || (tags.length >= 5 && !tags.includes(tag.id))} onPress={() => { setTags(current => current.includes(tag.id) ? current.filter(value => value !== tag.id) : [...current, tag.id]); setDirty(true); }} />)}</View></>}
            <View><Text className="font-semi text-[16px] text-ink">Foto’s en video’s</Text><Text className="mt-1 text-[13px] text-ink-50">Maximaal 4 bijlagen, 20 MB per bestand. JPG, PNG, WebP, MP4, MOV of WebM.</Text></View>
            {existing.map(media => <View key={media.id} className="gap-2"><MediaGallery media={[media]} /><Action label="Bijlage verwijderen" disabled={sending} onPress={() => { setRemoved(current => [...current, media.id]); setDirty(true); }} /></View>)}
            {assets.map((asset, index) => <View key={`${asset.uri}:${index}`} className="flex-row items-center gap-3 rounded-2xl bg-white p-3">
              {asset.type === 'video' ? <Play size={36} color="#127A79" /> : <Image source={{ uri: asset.uri }} style={{ width: 60, height: 60, borderRadius: 8 }} />}
              <Text className="flex-1 text-ink" numberOfLines={2}>{asset.fileName ?? (asset.type === 'video' ? 'Video' : 'Foto')}</Text><Pressable accessibilityRole="button" accessibilityLabel="Geselecteerde bijlage verwijderen" disabled={sending} onPress={() => { setAssets(current => current.filter((_, i) => i !== index)); setDirty(true); }}><Text className="font-semi text-mint-700">Verwijder</Text></Pressable>
            </View>)}
            <Action label="Foto of video toevoegen" disabled={sending || existing.length + assets.length >= 4} onPress={() => void pick()} />
            <Text className="text-[12px] text-ink-50">Een internetverbinding is nodig om te plaatsen. Deel alleen media die je mag delen.</Text>
            <Action label={sending ? 'Versturen…' : id ? 'Wijzigingen opslaan' : 'Bericht plaatsen'} primary disabled={sending || !body.trim()} onPress={() => void submit()} />
          </ScrollView>
        </KeyboardAvoidingView>}
  </SafeAreaView>;
}

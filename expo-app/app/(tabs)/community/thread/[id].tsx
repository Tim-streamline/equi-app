import { useEffect, useRef, useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, RefreshControl, ScrollView, Text, TextInput, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useDb } from '@/db/provider';
import { communityRequest, useCommunityResource } from '@/hooks/useCommunity';
import type { CommunityReply, CommunityThreadData } from '@/lib/community';
import { SubHeader } from '@/components/ui/SubHeader';
import { CommunityCard } from '@/components/community/CommunityCard';
import { Action, MembershipNotice, Pagination, ResourceState } from '@/components/community/Common';

export default function CommunityThread() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { isLoggedIn } = useDb();
  const [page, setPage] = useState(1);
  const { data, error, refresh, refreshing } = useCommunityResource<CommunityThreadData>(`/api/community/posts/${id}?page=${page}`);
  const [body, setBody] = useState('');
  const [parent, setParent] = useState<CommunityReply | null>(null);
  const [sending, setSending] = useState(false);
  useEffect(() => {
    if (data) setParent(current => current ? data.replies.data.find(reply => reply.id === current.id) ?? null : null);
  }, [data]);
  const input = useRef<TextInput>(null);
  const scroll = useRef<ScrollView>(null);
  const back = () => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/community');
  const replyTo = (reply: CommunityReply) => { setParent(reply); scroll.current?.scrollToEnd({ animated: true }); input.current?.focus(); };
  const send = async () => {
    if (sending || !body.trim()) return;
    setSending(true);
    try {
      await communityRequest(`/api/community/posts/${id}/replies`, 'POST', { body: body.trim(), parent_reply_id: parent?.id ?? null });
      setBody(''); setParent(null);
      const last = Math.ceil(((data?.replies.total ?? 0) + 1) / 50);
      if (page !== last) setPage(last); else await refresh();
    } catch (e) { Alert.alert('Reactie niet geplaatst', e instanceof Error ? e.message : 'Probeer opnieuw.'); }
    finally { setSending(false); }
  };
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title="Discussie" onBack={back} />
    {!isLoggedIn ? <View className="gap-4 p-5"><Text className="text-ink">Meld je gratis aan om deze discussie te lezen.</Text><Action label="Inloggen" primary onPress={() => router.push({ pathname: '/onboarding/welcome', params: { communityPost: id } })} /></View>
      : <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <ScrollView ref={scroll} keyboardShouldPersistTaps="handled" refreshControl={<RefreshControl refreshing={refreshing && !!data} onRefresh={() => void refresh()} />} contentContainerStyle={{ paddingBottom: 24 }}>
          {!data ? <ResourceState error={error} retry={() => void refresh()} /> : <>
            <View className="px-5"><CommunityCard item={data.post} postId={id} canParticipate={data.canParticipate} locked={data.post.locked} detail onChange={refresh} /></View>
            <Text className="px-5 pb-3 pt-6 font-bold text-[20px] text-ink">{data.replies.total} reacties</Text>
            {!data.replies.total && <Text className="px-5 pb-5 text-ink-50">Nog geen reacties. Deel jouw ervaring of stel een vraag.</Text>}
            <View className="gap-3 px-5">{data.replies.data.map(reply => <CommunityCard key={reply.id} item={reply} postId={id} canParticipate={data.canParticipate} locked={data.post.locked} detail onReply={replyTo} onChange={refresh} />)}</View>
            <Pagination page={page} last={data.replies.last_page} change={next => { setPage(next); scroll.current?.scrollTo({ y: 0 }); }} />
            {data.post.locked ? <Text className="p-5 text-ink-70">Deze discussie is gesloten. Je kunt de berichten nog lezen en bewaren.</Text>
              : !data.canParticipate ? <View className="mt-5"><MembershipNotice /></View> : <View className="m-5 gap-3 rounded-2xl border border-ink-8 bg-white p-4">
                <Text className="font-semi text-[17px] text-ink">Jouw reactie</Text>
                {parent && <View className="gap-2 rounded-xl bg-mint-50 p-3"><Text className="font-semi text-mint-700">Antwoord op {parent.authorName}</Text><Text className="text-ink-70" numberOfLines={2}>{parent.body}</Text><Action label="Antwoordreferentie verwijderen" onPress={() => setParent(null)} /></View>}
                <TextInput ref={input} accessibilityLabel="Jouw reactie" value={body} onChangeText={setBody} editable={!sending} placeholder="Schrijf een reactie…" multiline maxLength={10000} textAlignVertical="top" className="min-h-28 text-[15px] text-ink" />
                <Action label={sending ? 'Plaatsen…' : 'Reactie plaatsen'} primary disabled={sending || !body.trim()} onPress={() => void send()} />
              </View>}
          </>}
        </ScrollView>
      </KeyboardAvoidingView>}
  </SafeAreaView>;
}

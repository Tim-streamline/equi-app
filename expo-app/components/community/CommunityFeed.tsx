import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { BellOff, Bookmark, Plus } from 'lucide-react-native';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useCommunityResource } from '@/hooks/useCommunity';
import { communityQuery, type CommunityFeedData } from '@/lib/community';
import { CommunityCard } from './CommunityCard';
import { Action, Pagination, ResourceState } from './Common';
import { useDb } from '@/db/provider';

export function CommunityFeed() {
  const [category, setCategory] = useState('');
  const [bookmarked, setBookmarked] = useState(false);
  const [page, setPage] = useState(1);
  const { data, error, refresh, refreshing } = useCommunityResource<CommunityFeedData>(communityQuery(category, bookmarked, page));
  const padBottom = useTabBarPadding();
  const { isLoggedIn } = useDb();
  return <SafeAreaView edges={['top']} className="flex-1 bg-canvas">
    <ScrollView contentContainerStyle={{ paddingBottom: padBottom }} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}>
      <View className="mb-4 mt-2 flex-row items-center justify-between px-5">
        <View><Text className="font-semi text-[11px] tracking-[2px] text-mint-700">COMMUNITY</Text><Text className="mt-1 font-bold text-[28px] text-ink">Vraag & deel</Text></View>
        <Pressable accessibilityLabel="Gedempte auteurs" accessibilityRole="button" onPress={() => router.push('/(tabs)/community/mutes')} className="rounded-full bg-white p-3"><BellOff size={20} color="#127A79" /></Pressable>
      </View>
      <Text className="mb-4 px-5 text-[13px] leading-5 text-ink-70">Samen leren over je paard. Shelley leest en reageert mee wanneer ze kan. Een antwoord is niet gegarandeerd.</Text>
      {!isLoggedIn ? <View className="px-5"><Action label="Aanmelden" onPress={() => router.push('/onboarding/welcome')} /></View> : <>
        {data?.canParticipate && ( <Pressable accessibilityRole="button" accessibilityLabel="Nieuw bericht" onPress={() => router.push('/(tabs)/community/compose')} className="mx-5 mb-4 flex-row items-center gap-3 rounded-2xl border border-ink-8 bg-white p-4">
          <View className="rounded-full bg-mint-50 p-2"><Plus size={20} color="#127A79" /></View><Text className="flex-1 text-[14px] text-ink-50">Stel je vraag aan de community…</Text>
        </Pressable>)}
        <View className="mb-3 flex-row gap-2 px-5">
          <Pressable accessibilityRole="tab" accessibilityState={{ selected: !bookmarked }} onPress={() => { setBookmarked(false); setPage(1); }}><Chip label="Alle berichten" variant={!bookmarked ? 'filterActive' : 'outline'} /></Pressable>
          <Pressable accessibilityRole="tab" accessibilityLabel="Bewaarde berichten" accessibilityState={{ selected: bookmarked }} onPress={() => { setBookmarked(true); setPage(1); }}><Chip variant={bookmarked ? 'filterActive' : 'outline'}><Bookmark size={13} color={bookmarked ? '#fff' : '#127A79'} /><Text className={`ml-1 font-semi text-[12px] ${bookmarked ? 'text-white' : 'text-mint-700'}`}>Bewaard</Text></Chip></Pressable>
        </View>
        {data && <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 20, gap: 8, paddingBottom: 16 }}>
          {[{ id: '', label: 'Alles' }, ...data.categories].map(option => <Pressable key={option.id} accessibilityRole="button" accessibilityState={{ selected: category === option.id }} onPress={() => { setCategory(option.id); setPage(1); }}><Chip label={option.label} variant={category === option.id ? 'default' : 'outline'} /></Pressable>)}
        </ScrollView>}
        {!data ? <ResourceState error={error} retry={() => void refresh()} /> : <>
          <View className="gap-3 px-5">{data.posts.data.map(post => <CommunityCard key={post.id} item={post} postId={post.id} canParticipate={data.canParticipate} locked={post.locked} onChange={refresh} />)}</View>
          {!data.posts.data.length && <View className="px-8 py-10"><Text className="text-center font-semi text-[18px] text-ink">{bookmarked ? 'Nog geen bewaarde berichten' : 'Nog geen berichten'}</Text><Text className="mt-2 text-center text-ink-50">{bookmarked ? 'Bewaar een bericht met het bladwijzer-icoon. Verborgen berichten en gedempte auteurs worden niet getoond.' : 'In deze categorie is het nog rustig. Stel gerust de eerste vraag.'}</Text></View>}
          <Pagination page={page} last={data.posts.last_page} change={setPage} />
        </>}
      </>}
    </ScrollView>
  </SafeAreaView>;
}

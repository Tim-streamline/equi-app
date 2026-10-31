import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { BellOff, Bookmark, Plus } from 'lucide-react-native';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useCommunityResource } from '@/hooks/useCommunity';
import { communityQuery, type CommunityFeedData } from '@/lib/community';
import { CommunityCard } from './CommunityCard';
import { Action, Pagination, ResourceState } from './Common';
import { useDb } from '@/db/provider';
import { selectionHaptic } from '@/lib/haptics';
import { OfflineStrip } from '@/components/ui/OfflineStrip';
import { HEADER_ICON } from '@/components/ui/AppHeader';
import { IconButton } from '@/components/ui/IconButton';

/** Community's header action (see the pager layout): the muted authors list. */
export function MutedAuthorsButton() {
  return (
    <IconButton accessibilityLabel="Gedempte auteurs" hitSlop={0} onPress={() => router.push('/(tabs)/community/mutes')}>
      <BellOff size={22} color={HEADER_ICON} />
    </IconButton>
  );
}

export function CommunityFeed() {
  const [category, setCategory] = useState('');
  const [bookmarked, setBookmarked] = useState(false);
  const [page, setPage] = useState(1);
  const { data, error, refresh, refreshing } = useCommunityResource<CommunityFeedData>(communityQuery(category, bookmarked, page));
  const padBottom = useTabBarPadding();
  const { isLoggedIn } = useDb();
  return <View className="flex-1 bg-canvas">
    {!!error && <OfflineStrip message={error} stale={!!data} onRetry={() => void refresh()} />}
    <ScrollView contentContainerStyle={{ paddingTop: 16, paddingBottom: padBottom }} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}>
      {!isLoggedIn ? <View className="px-5"><Action label="Aanmelden" onPress={() => router.push('/onboarding/welcome')} /></View> : <>
        {data?.canParticipate && ( <Pressable accessibilityRole="button" accessibilityLabel="Nieuw bericht" onPress={() => router.push('/(tabs)/community/compose')} className="mx-5 mb-2 flex-row items-center gap-3 rounded-2xl border border-ink-8 bg-white p-4">
          <View className="rounded-full bg-mint-50 p-2"><Plus size={20} color="#127A79" /></View><Text className="flex-1 text-[15px] text-ink-70">Stel je vraag aan de community…</Text>
        </Pressable>)}
        <Text className="mb-4 px-5 text-[14px] leading-5 text-ink-70">Shelley leest mee wanneer ze kan; een antwoord is niet gegarandeerd.</Text>
        <View accessibilityRole="tablist" className="mx-5 mb-3 flex-row rounded-pill bg-canvas-2 p-1">
          {[{ saved: false, label: 'Alle berichten' }, { saved: true, label: 'Bewaard' }].map(option => {
            const selected = bookmarked === option.saved;
            return <Pressable key={option.label} accessibilityRole="tab" accessibilityLabel={option.saved ? 'Bewaarde berichten' : option.label} accessibilityState={{ selected }}
              onPress={() => { if (!selected) selectionHaptic(); setBookmarked(option.saved); setPage(1); }}
              className={`min-h-11 flex-1 flex-row items-center justify-center gap-1.5 rounded-pill ${selected ? 'bg-white shadow-sm' : ''}`}>
              {option.saved && <Bookmark size={14} color={selected ? '#1B2A2A' : 'rgba(27,42,42,0.7)'} />}
              <Text className={`font-semi text-[14px] ${selected ? 'text-ink' : 'text-ink-70'}`}>{option.label}</Text>
            </Pressable>;
          })}
        </View>
        {data && <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 20, gap: 8, paddingBottom: 12 }}>
          {[{ id: '', label: 'Alles' }, ...data.categories].map(option => <Pressable key={option.id} accessibilityRole="button" accessibilityState={{ selected: category === option.id }} onPress={() => { setCategory(option.id); setPage(1); }} className="min-h-11 justify-center"><Chip label={option.label} variant={category === option.id ? 'filterActive' : 'outline'} /></Pressable>)}
        </ScrollView>}
        {!data ? !error && <ResourceState error={null} retry={() => void refresh()} /> : <>
          <View className="gap-3 px-5">{data.posts.data.map(post => <CommunityCard key={post.id} item={post} postId={post.id} canParticipate={data.canParticipate} locked={post.locked} onChange={refresh} />)}</View>
          {!data.posts.data.length && <View className="px-8 py-10"><Text className="text-center font-semi text-[18px] text-ink">{bookmarked ? 'Nog geen bewaarde berichten' : 'Nog geen berichten'}</Text><Text className="mt-2 text-center text-ink-70">{bookmarked ? 'Bewaar een bericht met het bladwijzer-icoon. Verborgen berichten en gedempte auteurs worden niet getoond.' : 'In deze categorie is het nog rustig. Stel gerust de eerste vraag.'}</Text></View>}
          <Pagination page={page} last={data.posts.last_page} change={setPage} />
        </>}
      </>}
    </ScrollView>
  </View>;
}

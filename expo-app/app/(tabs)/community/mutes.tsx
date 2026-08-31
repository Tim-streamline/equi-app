import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useDb } from '@/db/provider';
import { communityRequest, useCommunityResource } from '@/hooks/useCommunity';
import type { MutedAuthor } from '@/lib/community';
import { Action, ResourceState } from '@/components/community/Common';
import { SubHeader } from '@/components/ui/SubHeader';

export default function CommunityMutes() {
  const { isLoggedIn } = useDb();
  const { data, error, refresh } = useCommunityResource<{ data: MutedAuthor[] }>('/api/community/mutes');
  const [busy, setBusy] = useState(false);
  const unmute = async (author: MutedAuthor) => {
    setBusy(true);
    try { await communityRequest(`/api/community/mutes/${author.type}/${author.id}`, 'DELETE'); await refresh(); }
    catch (e) { Alert.alert('Niet gelukt', e instanceof Error ? e.message : 'Probeer opnieuw.'); }
    finally { setBusy(false); }
  };
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title="Gedempte auteurs" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/community')} />
    <ScrollView contentContainerStyle={{ padding: 20, gap: 16 }}>
      <Text className="text-[15px] leading-5 text-ink-70">Berichten en reacties van gedempte auteurs zijn voor jou verborgen, ook in Bewaard. Zet dempen uit om ze weer te zien.</Text>
      {!isLoggedIn ? <Action label="Inloggen" onPress={() => router.push('/onboarding/welcome')} /> : !data ? <ResourceState error={error} retry={() => void refresh()} /> : <>
        {!data.data.length && <Text className="text-ink-50">Je hebt geen auteurs gedempt.</Text>}
        {data.data.map(author => <View key={`${author.type}:${author.id}`} className="flex-row items-center gap-3 rounded-2xl bg-white p-4"><Text className="flex-1 font-semi text-ink">{author.name}</Text><Action label="Dempen uit" disabled={busy} onPress={() => void unmute(author)} /></View>)}
      </>}
    </ScrollView>
  </SafeAreaView>;
}

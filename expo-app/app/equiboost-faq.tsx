import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Minus, Play, Plus } from 'lucide-react-native';
import { getApiBaseUrl } from '@/db/auth';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { PLAIN_CARD } from '@/components/ui/styles';
import { PROTOCOL_TEXT as T } from '@/components/protocol/styles';
import { libraryPath } from '@/lib/horse-dashboard';
import type { PlusPageData } from '@/lib/plus-page';

/** Frequently asked questions for owners who are in an EquiBoost traject, edited in the admin. */
export default function EquiBoostFaq() {
  const [data, setData] = useState<PlusPageData | null>(null);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  const [open, setOpen] = useState<number | null>(null);
  useFocusEffect(useCallback(() => {
    let mounted = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    setError('');
    void fetch(`${getApiBaseUrl()}/api/protocol-page`, { signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(); const value = await response.json(); if (!controller.signal.aborted) setData(value); })
      .catch(() => { if (mounted) setError('De veelgestelde vragen konden niet worden geladen. Controleer je verbinding en probeer het opnieuw.'); })
      .finally(() => clearTimeout(timeout));
    return () => { mounted = false; controller.abort(); clearTimeout(timeout); };
  }, [retry]));
  const c = data?.content;
  const video = data?.memberFaqVideo;
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title="Veelgestelde vragen" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/protocol')} />
    {c ? <ScrollView contentContainerStyle={{ width: '100%', maxWidth: 560, alignSelf: 'center', paddingHorizontal: 20, paddingTop: 8, paddingBottom: 40 }}>
      <Text accessibilityRole="header" className={T.title}>{c.memberFaqTitle}</Text>
      <Text className={`mt-2 ${T.lead}`}>{c.memberFaqBody}</Text>

      {video && <Pressable accessibilityRole="button" accessibilityLabel={`Bekijk de video: ${video.title}`} onPress={() => router.push(libraryPath(video))}
        className="mt-6 flex-row items-center gap-4 rounded-[22px] bg-teal-500 p-4">
        <View className="h-12 w-12 items-center justify-center rounded-full bg-white/20"><Play size={22} color="#FFFFFF" fill="#FFFFFF" /></View>
        <View className="flex-1"><Text className="font-sans text-[13px] leading-[18px] text-white/80">Bekijk de video</Text><Text className="font-semi text-[16px] leading-[22px] text-white">{video.title}</Text></View>
      </Pressable>}

      <View className="mt-4">
        {c.memberFaqs.map((item, index) => <View key={index} className="border-b border-ink-15 py-4">
          <Pressable accessibilityRole="button" accessibilityState={{ expanded: open === index }} onPress={() => setOpen(open === index ? null : index)} className="min-h-11 flex-row items-center gap-4">
            <Text className="flex-1 font-semi text-[16px] leading-[21px] text-ink">{item.question}</Text>{open === index ? <Minus size={18} color="#127A79" /> : <Plus size={18} color="#127A79" />}
          </Pressable>
          {open === index && <Text className="pt-3 text-[15px] leading-[23px] text-ink-70">{item.answer}</Text>}
        </View>)}
      </View>

      <View className={`mt-8 gap-3 ${PLAIN_CARD}`}>
        <Text className={T.cardTitle}>Staat je vraag er niet bij?</Text>
        <Text className={T.body}>Shelby denkt elke dag met je mee over je paard en je EquiBoost traject.</Text>
        <Button title="Vraag het Shelby" onPress={() => router.push('/nova-chat')} />
      </View>
    </ScrollView>
      : <View className="flex-1 items-center justify-center gap-4 px-8">{error ? <><Text className={`text-center ${T.body}`}>{error}</Text><Button title="Opnieuw proberen" onPress={() => setRetry(n => n + 1)} /></> : <ActivityIndicator />}</View>}
  </SafeAreaView>;
}

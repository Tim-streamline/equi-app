import { useCallback, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { getApiBaseUrl } from '@/db/auth';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { PlusPage } from '@/components/plus/PlusPage';
import { PlusPageData } from '@/lib/plus-page';

export default function PlusScreen() {
  const [data, setData] = useState<PlusPageData | null>(null);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    let active = true;
    const timer = setTimeout(() => controller.abort(), 15000);
    setError('');
    void fetch(`${getApiBaseUrl()}/api/plus-page`, { signal: controller.signal, headers: { Accept: 'application/json' } })
      .then(async response => {
        if (!response.ok) throw new Error('Plus-informatie is niet beschikbaar.');
        const page = await response.json() as PlusPageData;
        if (active) setData(page);
      }).catch(() => { if (active) setError('De Plus-pagina kon niet worden geladen. Controleer je verbinding en probeer opnieuw.'); })
      .finally(() => clearTimeout(timer));
    return () => { active = false; clearTimeout(timer); controller.abort(); };
  }, [retry]));
  return <SafeAreaView edges={['top', 'bottom']} className="flex-1 bg-canvas">
    <View className="w-full self-center" style={{ maxWidth: 560 }}><SubHeader title="Ontdek Plus" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/home')} /></View>
    {data ? <PlusPage data={data} apiBaseUrl={getApiBaseUrl()} onIntake={() => router.push('/intake')} /> :
      <View className="flex-1 items-center justify-center gap-4 px-6">{error ? <><Text accessibilityRole="alert" className="text-center text-ink-70">{error}</Text><Button title="Opnieuw proberen" onPress={() => setRetry(value => value + 1)} /></> : <ActivityIndicator accessibilityLabel="Plus-pagina laden" color="#127A79" />}</View>}
  </SafeAreaView>;
}

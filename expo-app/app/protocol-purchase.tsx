import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useCurrentHorseId, useHorse } from '@/db/hooks';
import { getApiBaseUrl } from '@/db/auth';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { PlusPage } from '@/components/plus/PlusPage';
import { PlusPageData } from '@/lib/plus-page';
import { dashboardRequest } from '@/hooks/useHorseDashboard';
import { accountSession } from '@/lib/account-session';

export default function ProtocolPurchase() {
  const horseId = useCurrentHorseId();
  const horse = useHorse();
  const [data, setData] = useState<PlusPageData | null>(null);
  const [message, setMessage] = useState(''), [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  const [busy, setBusy] = useState(false);
  const pending = useRef(false), mounted = useRef(true), selectedHorse = useRef(horseId);
  selectedHorse.current = horseId;
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  async function createProtocol() {
    if (pending.current) return false;
    if (!horseId) { router.push('/onboarding/add-horse'); return false; }
    const revision = accountSession.revision;
    pending.current = true; setBusy(true); setMessage('');
    try {
      const { bookingId } = await dashboardRequest('/api/protocol-purchases/create', { horse_id: horseId });
      if (!mounted.current || revision !== accountSession.revision || selectedHorse.current !== horseId) return false;
      router.replace({ pathname: '/intake', params: { bookingId } });
      return true;
    } catch {
      if (mounted.current && revision === accountSession.revision && selectedHorse.current === horseId) {
        setMessage('Je protocol kon niet worden geopend. Controleer je verbinding en probeer het opnieuw.');
      }
      return false;
    } finally {
      pending.current = false;
      if (mounted.current) setBusy(false);
    }
  }
  useFocusEffect(useCallback(() => {
    let mounted = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    setError('');
    void fetch(`${getApiBaseUrl()}/api/protocol-page`, { signal: controller.signal })
      .then(async response => { if (!response.ok) throw new Error(); const value = await response.json(); if (!controller.signal.aborted) setData(value); })
      .catch(() => { if (mounted) setError('De protocolpagina kon niet worden geladen.'); })
      .finally(() => clearTimeout(timeout));
    return () => { mounted = false; controller.abort(); clearTimeout(timeout); };
  }, [retry]));
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title={data?.content.pageTitle ?? 'Begeleiding op maat'} onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/home')} />
    {data ? <PlusPage data={data} horseName={String(horse.name || 'je paard')} apiBaseUrl={getApiBaseUrl()} access={{ intakeAvailable: false, checkoutAvailable: false }} busy={busy} error={message}
      onIntake={createProtocol} />
      : <View className="flex-1 items-center justify-center gap-4">{error ? <><Text>{error}</Text><Button title="Opnieuw proberen" onPress={() => setRetry(n => n + 1)} /></> : <ActivityIndicator />}</View>}
  </SafeAreaView>;
}

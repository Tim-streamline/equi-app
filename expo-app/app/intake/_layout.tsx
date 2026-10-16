import { useEffect, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { useQuery, useStatus } from '@powersync/react';
import { useCurrentUserId } from '@/db/hooks';
import { router } from 'expo-router';
import { Button } from '@/components/ui/Button';
import { dashboardRequest } from '@/hooks/useHorseDashboard';
import type { PlusAccess } from '@/lib/plus-access';
import { Redirect, Stack, useSegments } from 'expo-router';
import { useIntake } from '@/lib/intake/store';

// File-based routing auto-discovers screens; no explicit <Stack.Screen> needed
// here unless we want per-screen options. Header is hidden globally — each
// screen renders its own SubHeader / themed top bar.
export default function IntakeLayout() {
  const { state, loaded, horseId, bookingId } = useIntake();
  const userId = useCurrentUserId();
  const sync = useStatus();
  const { data: horses, isLoading: horsesLoading } = useQuery("SELECT id FROM horses WHERE owner_id = ? AND status = 'active' ORDER BY created_at", [userId]);
  const segments = useSegments();
  const [access, setAccess] = useState<{ horseId: string; value: PlusAccess }>();
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let active = true; setError('');
    if (!horseId || !loaded || bookingId) return;
    void dashboardRequest(`/api/protocol-purchases/access?horse_id=${horseId}`).then(value => { if (active) setAccess({ horseId, value }); })
      .catch(() => { if (active) setError('Je toegang kon niet worden gecontroleerd. Controleer je verbinding.'); });
    return () => { active = false; };
  }, [horseId, loaded, bookingId, retry]);
  if (segments.at(-1) === 'reminder') return <Stack screenOptions={{ headerShown: false }} />;
  if (!horseId) {
    if (horsesLoading || !sync.hasSynced || horses.length) return <View className="flex-1 items-center justify-center gap-4 p-6"><ActivityIndicator accessibilityLabel="Je paarden laden" /><Text>Je paarden worden geladen…</Text><Button title="Naar Home" onPress={() => router.replace('/(tabs)/(pager)/home')} /></View>;
    return <Redirect href="/onboarding/add-horse" />;
  }
  if (!loaded) return null;
  if (!bookingId) {
    if (error) return <View className="flex-1 items-center justify-center gap-4 p-6"><Text>{error}</Text><Button title="Opnieuw proberen" onPress={() => setRetry(n => n + 1)} /></View>;
    if (access?.horseId !== horseId) return null;
    if (!access.value.intakeAvailable && !state.submittedAt) return <View className="flex-1 items-center justify-center gap-4 p-6"><Text>{'Koop een protocol voor dit paard om de bijbehorende intake te starten.'}</Text><Button title="Protocol kopen" onPress={() => router.replace('/protocol-purchase')} /></View>;
    return <View className="flex-1 items-center justify-center gap-4 p-6"><ActivityIndicator /><Text>Je intake wordt gesynchroniseerd…</Text><Button title="Naar Home" onPress={() => router.replace('/(tabs)/(pager)/home')} /></View>;
  }
  if (state.submittedAt && ['submit', 'welcome'].includes(segments.at(-1) ?? '')) return <Redirect href="/intake/sent" />;
  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: '#FBF8F3' },
      }}
    />
  );
}

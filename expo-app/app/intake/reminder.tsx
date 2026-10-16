import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useDb } from '@/db/provider';
import { dashboardRequest } from '@/hooks/useHorseDashboard';
import { Button } from '@/components/ui/Button';
import { useIntake } from '@/lib/intake/store';

export default function IntakeReminder() {
  const { bookingId, userId } = useLocalSearchParams<{ bookingId: string; userId: string }>();
  const { currentUserId, selectedHorseId, selectHorse } = useDb();
  const { horseId, loaded } = useIntake();
  const [destination, setDestination] = useState<any>(), [error, setError] = useState('');
  useEffect(() => {
    let active = true;
    if (!currentUserId) return;
    if (userId !== currentUserId || !/^[a-f0-9-]{36}$/i.test(bookingId ?? '')) { setError('Deze herinnering hoort bij een ander account.'); return; }
    void dashboardRequest(`/api/intakes/${bookingId}/resume`).then(result => {
      if (!active) return;
      selectHorse(result.horseId); setDestination(result);
    }).catch(() => { if (active) setError('Deze intake kon niet worden geopend. Ga naar Home om je intake te openen.'); });
    return () => { active = false; };
  }, [bookingId, currentUserId, userId]);
  useEffect(() => {
    // Selecting an ID does not mean PowerSync and the keyed intake provider are ready.
    // Keep the reminder route mounted until both have switched to the intended horse.
    if (!destination || selectedHorseId !== destination.horseId || horseId !== destination.horseId || !loaded) return;
    router.replace(destination.submitted ? '/intake/sent' : destination.stage === 'ready' ? '/intake/submit' : destination.section === 'photos' || destination.section === '__photos'
      ? { pathname: '/intake/photos', params: { group: destination.field ?? '' } }
      : destination.section ? { pathname: '/intake/section/[id]', params: { id: destination.section, field: destination.field ?? '' } } : '/intake/overview');
  }, [destination, selectedHorseId, horseId, loaded]);
  return <View className="flex-1 items-center justify-center gap-4 p-6"><Text>{error || 'Je intake wordt geopend…'}</Text>{!!error && <Button title="Naar Home" onPress={() => router.replace('/(tabs)/(pager)/home')} />}</View>;
}

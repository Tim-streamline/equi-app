import { formatIntakeDate } from '@/lib/intake/submission';
import { useQuery } from '@powersync/react';
import { Text, View } from 'react-native';
import { router } from 'expo-router';
import { useCurrentHorseId, useCurrentUserId, useHorse } from '@/db/hooks';
import { Button } from '@/components/ui/Button';

export function PurchasedProtocols() {
  const horseId = useCurrentHorseId(), userId = useCurrentUserId();
  const horse = useHorse();
  const { data } = useQuery<{ id: string; title: string; status: string; booking_id: string; submitted_at: string | null; completed_at: string | null }>(`
    SELECT p.id, p.title, p.status, i.id AS booking_id, i.submitted_at, i.completed_at
    FROM protocols p JOIN intake_bookings i ON i.protocol_id = p.id
    WHERE p.horse_id = ? AND i.user_id = ? AND p.status != 'archived' AND i.status != 'cancelled'
    ORDER BY p.created_at DESC, p.id DESC`, [horseId, userId]);
  if (!data.length) return null;
  return <View className="mb-4 gap-3">
    {data.map(protocol => {
      const completedAt = protocol.completed_at ?? protocol.submitted_at;
      return <View key={protocol.id} className="gap-3 rounded-card bg-white p-5">
      <Text className="font-bold text-[18px] text-teal-700">{protocol.title === 'Persoonlijk protocol' ? `Protocol van ${horse.name || 'je paard'}` : protocol.title}</Text>
      <Text>{protocol.status === 'completed' ? 'Afgerond' : protocol.status === 'active' ? 'Actief protocol' : completedAt ? 'Je intake is verstuurd. Je behandelaar stelt je protocol op.' : 'Vul de intake voor dit protocol in.'}</Text>
      {!!completedAt && <Text className="text-sm text-ink-70">Intake ingestuurd op {formatIntakeDate(completedAt)}</Text>}
      {(!!completedAt || protocol.status !== 'completed') && <Button title={completedAt ? 'Intake bekijken' : 'Intake openen'} onPress={() => router.push({ pathname: '/intake', params: { bookingId: protocol.booking_id } })} />}
    </View>; })}
  </View>;
}

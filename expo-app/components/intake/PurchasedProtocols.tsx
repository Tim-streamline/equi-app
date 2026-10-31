import { formatIntakeDate } from '@/lib/intake/submission';
import { useQuery } from '@powersync/react';
import { Text, View } from 'react-native';
import { router } from 'expo-router';
import { useCurrentHorseId, useCurrentUserId } from '@/db/hooks';
import { useDb } from '@/db/provider';
import { Button } from '@/components/ui/Button';
import { PROTOCOLS_FOR_ALL_HORSES, PROTOCOLS_FOR_HORSE, type PurchasedProtocolRow } from '@/lib/intake/protocol-queries';

/**
 * One card per purchased protocol and its intake. By default for the selected
 * horse; with `allHorses` for every active horse of the user (Home), so intakes
 * of several horses each get their own card.
 */
export function PurchasedProtocols({ allHorses = false }: { allHorses?: boolean }) {
  const horseId = useCurrentHorseId(), userId = useCurrentUserId();
  const { selectHorse } = useDb();
  const { data } = useQuery<PurchasedProtocolRow>(allHorses ? PROTOCOLS_FOR_ALL_HORSES : PROTOCOLS_FOR_HORSE, allHorses ? [userId] : [horseId, userId]);
  if (!data.length) return null;
  return <View className="mb-4 gap-3">
    {data.map(protocol => {
      const completedAt = protocol.completed_at ?? protocol.submitted_at;
      const horseName = protocol.horse_name || 'je paard';
      const generic = protocol.title === 'Persoonlijk protocol';
      return <View key={protocol.id} className="gap-3 rounded-card bg-white p-5">
      {/* With several horses a custom title alone does not say which horse it is for. */}
      {allHorses && !generic && <Text className="-mb-2 font-semi text-[14px] text-ink-70">{horseName}</Text>}
      <Text className="font-bold text-[18px] text-teal-700">{generic ? `Protocol van ${horseName}` : protocol.title}</Text>
      <Text>{protocol.status === 'completed' ? 'Afgerond' : protocol.status === 'active' ? 'Actief protocol' : completedAt ? 'Je intake is verstuurd. Je behandelaar stelt je protocol op.' : 'Vul de intake voor dit protocol in.'}</Text>
      {!!completedAt && <Text className="text-sm text-ink-70">Intake ingestuurd op {formatIntakeDate(completedAt)}</Text>}
      {(!!completedAt || protocol.status !== 'completed') && <Button title={completedAt ? 'Intake bekijken' : 'Intake openen'} onPress={() => {
        // The intake looks up its booking under the selected horse.
        selectHorse(protocol.horse_id);
        router.push({ pathname: '/intake', params: { bookingId: protocol.booking_id } });
      }} />}
    </View>; })}
  </View>;
}

import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { ChevronRight } from 'lucide-react-native';
import { Button } from '@/components/ui/Button';
import { useSupplementIntakesForDate } from '@/db/hooks';
import type { DashboardPhase, DashboardOrderItem } from '@/lib/horse-dashboard';
import { phaseOrderItems } from '@/lib/protocol-calendar';
import { todayGroups } from '@/lib/protocol-today';
import { DoseBuildupSchedule } from './DoseBuildupSchedule';
import { PROTOCOL_SCALE as S, PROTOCOL_TEXT as T } from './styles';

export function ProtocolPhaseContent({ phase, date, orderItems, onRefresh }: {
  phase: DashboardPhase; date: string; orderItems: DashboardOrderItem[]; onRefresh: () => Promise<void>;
}) {
  const [detail, setDetail] = useState<string | null>(null);
  const local = useSupplementIntakesForDate(date);
  // Start with the server's effective dose, then honor the same saved daily dose as Today.
  const supplements = todayGroups(phase.supplements.map(item => ({
    ...item, dosage: item.buildup?.currentDosage ?? item.dosage,
  })), local).flatMap(group => group.items);
  const active = phase.state === 'active' || phase.state === 'done';
  const selected = active ? supplements.find(item => item.id === detail) : null;
  if (selected) return <View className="gap-4">
    <Button title="Terug naar fase" variant="text" onPress={() => setDetail(null)} />
    <Text accessibilityRole="header" className={T.cardTitle}>{selected.name}</Text>
    <Text className={T.secondary}>{selected.dosage ?? 'Dosering niet ingesteld'} · {selected.frequencyLabel}</Text>
    <DoseBuildupSchedule item={selected} date={date} />
    {!!selected.instructions && <View className="gap-2"><Text accessibilityRole="header" className={T.section}>Instructies</Text><Text className={T.body}>{selected.instructions}</Text></View>}
    {!!selected.description && <View className="gap-2"><Text accessibilityRole="header" className={T.section}>Over dit product</Text><Text className={T.body}>{selected.description}</Text></View>}
  </View>;
  return <View className="gap-4">
    {!!phase.description && <Text className={T.body}>{phase.description}</Text>}
    {active ? <>
      {!phase.contentAvailable && <View className="gap-2"><Text className={T.secondary}>Maak verbinding om de volledige fase op te halen.</Text><Button title="Opnieuw laden" onPress={() => void onRefresh()} /></View>}
      {supplements.map(item => <View key={item.id} className="flex-row items-center gap-3 border-b border-ink-8 py-3">
        <View className="flex-1 gap-1"><Text className={T.section}>{item.name}</Text><Text className={T.secondary}>{item.dosage ?? 'Dosering niet ingesteld'} per gift</Text></View>
        <Pressable accessibilityRole="button" accessibilityLabel={`Uitleg over ${item.name}`} onPress={() => setDetail(item.id)} className="min-h-[44px] flex-row items-center gap-1"><Text className={T.link}>Uitleg</Text><ChevronRight size={17} color="#106E6D" /></Pressable>
      </View>)}
    </> : <>
      <View className="flex-row items-center justify-between gap-3"><Text accessibilityRole="header" className={T.section}>Wat je nodig hebt</Text><Text className={T.meta}>{phase.durationLabel}</Text></View>
      {phaseOrderItems(orderItems, [phase.id]).map(item => <View key={item.id} className="flex-row items-center justify-between gap-3 border-b border-ink-8 py-3"><Text className={`flex-1 ${T.section}`}>{item.name}</Text><Text className={T.secondary}>{item.quantityLabel ?? 'Hoeveelheid nog te bepalen'}</Text></View>)}
      <View className="rounded-[22px] bg-mint-50 p-4"><Text className={`${S.secondary} text-teal-700`}>Deze producten zijn voor een volgende fase. De dosering per gift en gebruiksinstructies zie je zodra deze fase actief is.</Text></View>
    </>}
  </View>;
}

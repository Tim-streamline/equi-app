import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { ChevronRight } from 'lucide-react-native';
import { Button } from '@/components/ui/Button';
import type { DashboardPhase, DashboardOrderItem } from '@/lib/horse-dashboard';
import { phaseOrderItems } from '@/lib/protocol-calendar';
import { DoseBuildupSchedule } from './DoseBuildupSchedule';

export function ProtocolPhaseContent({ phase, orderItems, onRefresh }: {
  phase: DashboardPhase; orderItems: DashboardOrderItem[]; onRefresh: () => Promise<void>;
}) {
  const [detail, setDetail] = useState<string | null>(null);
  const active = phase.state === 'active' || phase.state === 'done';
  const selected = active ? phase.supplements.find(item => item.id === detail) : null;
  if (selected) return <View className="gap-4">
    <Button title="Terug naar fase" variant="text" onPress={() => setDetail(null)} />
    <Text className="font-semi text-[18px] text-ink">{selected.name}</Text>
    <Text className="font-sans text-[14px] text-ink-70">{selected.buildup?.currentDosage ?? selected.dosage} · {selected.frequencyLabel}</Text>
    <DoseBuildupSchedule item={{ ...selected, dosage: selected.buildup?.currentDosage ?? selected.dosage }} date={selected.buildup?.date} />
    {!!selected.instructions && <View className="gap-2"><Text className="font-semi text-[16px] text-ink">Instructies</Text><Text className="font-sans text-[15px] leading-[24px] text-ink-70">{selected.instructions}</Text></View>}
    {!!selected.description && <View className="gap-2"><Text className="font-semi text-[16px] text-ink">Over dit product</Text><Text className="font-sans text-[15px] leading-[24px] text-ink-70">{selected.description}</Text></View>}
  </View>;
  return <View className="gap-4">
    {!!phase.description && <Text className="font-sans text-[15px] leading-[24px] text-ink-70">{phase.description}</Text>}
    {active ? <>
      {!phase.contentAvailable && <View className="gap-2"><Text className="text-[14px] text-ink-70">Maak verbinding om de volledige fase op te halen.</Text><Button title="Opnieuw laden" onPress={() => void onRefresh()} /></View>}
      {phase.supplements.map(item => <View key={item.id} className="flex-row items-center gap-3 border-b border-ink-8 py-3">
        <View className="flex-1 gap-1"><Text className="font-semi text-[16px] text-ink">{item.name}</Text><Text className="font-sans text-[14px] text-ink-70">{item.buildup?.currentDosage ?? item.dosage ?? 'Dosering niet ingesteld'} per gift</Text></View>
        <Pressable accessibilityRole="button" accessibilityLabel={`Uitleg over ${item.name}`} onPress={() => setDetail(item.id)} className="min-h-[44px] flex-row items-center gap-1"><Text className="font-semi text-[13px] text-teal-600">Uitleg</Text><ChevronRight size={17} color="#106E6D" /></Pressable>
      </View>)}
    </> : <>
      <View className="flex-row items-center justify-between gap-3"><Text className="font-semi text-[16px] text-ink">Wat je nodig hebt</Text><Text className="font-sans text-[13px] text-ink-70">{phase.durationLabel}</Text></View>
      {phaseOrderItems(orderItems, [phase.id]).map(item => <View key={item.id} className="flex-row items-center justify-between gap-3 border-b border-ink-8 py-3"><Text className="flex-1 font-semi text-[16px] text-ink">{item.name}</Text><Text className="font-sans text-[15px] text-ink-70">{item.quantityLabel ?? 'Hoeveelheid nog te bepalen'}</Text></View>)}
      <View className="rounded-[20px] bg-mint-50 p-4"><Text className="font-sans text-[14px] leading-[22px] text-teal-700">Deze producten zijn voor een volgende fase. De dosering per gift en gebruiksinstructies zie je zodra deze fase actief is.</Text></View>
    </>}
  </View>;
}

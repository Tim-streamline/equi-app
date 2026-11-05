import { useRef, useState, type ComponentType, type ReactNode } from 'react';
import { Alert, Pressable, Text, View } from 'react-native';
import { Check, ChevronRight, Soup } from 'lucide-react-native';
import { router } from 'expo-router';
import { Button } from '@/components/ui/Button';
import { useStoreMutations, useSupplementIntakesForDate } from '@/db/hooks';
import type { DashboardPhase, DashboardProtocol, DashboardSupplement } from '@/lib/horse-dashboard';
import { administrationKey, todayGroups } from '@/lib/protocol-today';
import { selectionHaptic } from '@/lib/haptics';

type SheetProps = { visible: boolean; title: string; subtitle?: string; children: ReactNode; onClose: () => void; footer?: ReactNode };
const COLORS = ['#18BAB0', '#0D5C5B', '#6D8C6B', '#B58339'];

export function ProtocolToday({ protocol, date, horseId, onPhase, SheetComponent: Sheet }: {
  protocol: DashboardProtocol; date: string; horseId: string; onPhase: (phase: DashboardPhase) => void;
  SheetComponent: ComponentType<SheetProps>;
}) {
  const mutations = useStoreMutations();
  const local = useSupplementIntakesForDate(date);
  const [pending, setPending] = useState(false);
  const busy = useRef(false);
  const [detailKey, setDetailKey] = useState<string | null>(null);
  const groups = todayGroups(protocol.today.items, local);
  const rows = groups.flatMap(group => group.items);
  const done = rows.filter(item => item.done).length;
  const percent = rows.length ? Math.round(done / rows.length * 100) : 0;
  const selected = rows.find(item => detailKey === `${date}:${administrationKey(item)}`);
  const active = protocol.phases.filter(phase => phase.state === 'active');
  const phaseIndex = (item: DashboardSupplement) => protocol.phases.findIndex(phase => item.phaseId ? phase.id === item.phaseId : phase.title === item.phaseTitle);
  const toggle = async (item: DashboardSupplement) => {
    if (busy.current) return false;
    busy.current = true;
    setPending(true);
    selectionHaptic();
    try {
      await mutations.toggleSupplementIntake(item.id, item.dosage, date, horseId, item.administration ?? 1);
      return true;
    } catch {
      Alert.alert('Niet opgeslagen', 'Probeer het opnieuw.');
      return false;
    } finally { busy.current = false; setPending(false); }
  };
  return <>
    <View className="mb-4 flex-row flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
      <Text accessibilityRole="header" className="font-bold text-[21px] leading-[28px] text-teal-900">{protocol.weekLabel ?? `Week ${protocol.currentWeek} van ${protocol.totalWeeks}`}</Text>
      <Text className="text-[14px] leading-[21px] text-ink-70">{protocol.dateLabel ?? new Date(`${date}T12:00:00`).toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long' })}</Text>
    </View>
    {rows.length > 0 && <View className="mb-5 gap-2">
        <View className="flex-row items-center justify-between gap-3">
          <Text className="font-semi text-[15px] text-ink">Vandaag afvinken · {done} van {rows.length} gedaan</Text>
          <Text className="font-semi text-[15px] text-teal-700">{percent}%</Text>
        </View>
        <View accessibilityRole="progressbar" accessibilityLabel="Dagelijkse voortgang" accessibilityValue={{ min: 0, max: rows.length, now: done, text: `${done} van ${rows.length} gedaan` }} className="h-2 overflow-hidden rounded-full bg-ink-8">
          <View className="h-full rounded-full bg-mint-500" style={{ width: `${percent}%` }} />
        </View>
    </View>}
    {active.length > 0 && <View className="mb-5 overflow-hidden rounded-[22px] bg-mint-50">
      {active.map((phase, index) => {
        const number = protocol.phases.findIndex(item => item.id === phase.id);
        return <Pressable key={phase.id} accessibilityRole="button" accessibilityLabel={`Bekijk ${phase.title}`}
          onPress={() => onPhase(phase)} className={`min-h-[56px] flex-row items-center gap-3 px-4 py-4 ${index > 0 ? 'border-t border-mint-100' : ''}`}>
          <View className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: COLORS[number % COLORS.length] }} />
          <Text className="flex-1 font-semi text-[17px] leading-[24px] text-teal-700">Fase {number + 1} · {phase.title}</Text>
          <View className="flex-row items-center gap-1"><Text className="font-semi text-[15px] text-teal-600">Uitleg</Text><ChevronRight size={17} color="#106E6D" /></View>
        </Pressable>;
      })}
    </View>}
    {rows.length > 0 && <>
      <View className="mb-4 flex-row items-center gap-2">
        <Soup size={20} color="#106E6D" />
        <Text className="flex-1 font-semi text-[16px] leading-[23px] text-teal-600">Meng alles door de geweekte bijvoeding</Text>
      </View>
    </>}
    {groups.map(group => <View key={group.label} className="mb-4">
      <View className="mb-1 flex-row items-center justify-between gap-3">
        <Text accessibilityRole="header" className="font-bold text-[21px] text-ink">{group.label}</Text>
        <Text className="font-semi text-[14px] text-ink-70">{group.done} van {group.items.length}</Text>
      </View>
      {group.items.map(item => {
        const number = phaseIndex(item);
        return <View key={administrationKey(item)} className="flex-row items-center gap-2">
          <Pressable accessibilityRole="checkbox" accessibilityLabel={`${item.name}, ${group.label}, ${item.dosage ?? 'dosering niet ingesteld'}`}
            accessibilityState={{ checked: !!item.done, disabled: pending }} disabled={pending} onPress={() => void toggle(item)}
            className="min-h-[48px] min-w-[44px] items-center justify-center">
            <View className={`h-7 w-7 items-center justify-center rounded-full border-2 ${item.done ? 'border-mint-500 bg-mint-500' : 'border-ink-30'}`}>
              {item.done && <Check size={17} color="white" />}
            </View>
          </Pressable>
          <View className="flex-1 flex-row items-center gap-2 border-b border-ink-8 py-3">
            <View className="flex-1 gap-1.5">
              <Text className={`text-[17px] leading-[25px] ${item.done ? 'text-ink-70' : 'text-ink'}`}><Text className="font-semi">{item.name}</Text>{'  '}<Text className="text-ink-70">{item.dosage ?? 'Dosering niet ingesteld'}</Text></Text>
              {active.length > 1 && number >= 0 && <View className="flex-row items-center gap-2">
                <View className="h-2 w-2 rounded-full" style={{ backgroundColor: COLORS[number % COLORS.length] }} />
                <Text className="text-[12px] text-ink-70">Fase {number + 1}</Text>
              </View>}
            </View>
            <Pressable accessibilityRole="button" accessibilityLabel={`Uitleg over ${item.name}, ${group.label}`}
              onPress={() => setDetailKey(`${date}:${administrationKey(item)}`)} className="min-h-[44px] flex-row items-center gap-1 px-1">
              <Text className="font-semi text-[15px] text-teal-600">Uitleg</Text><ChevronRight size={17} color="#106E6D" />
            </Pressable>
          </View>
        </View>;
      })}
    </View>)}
    {rows.length === 0 && <Text className="py-5 text-[15px] text-ink-70">Vandaag staat er niets gepland.</Text>}
    <Sheet visible={!!selected} title={selected?.name ?? ''}
      subtitle={selected ? `${selected.dosage ?? 'Dosering niet ingesteld'} · ${selected.frequencyLabel ?? `${selected.administrationsPerDay ?? 1}× per dag`}` : ''}
      onClose={() => setDetailKey(null)} footer={selected ? <View className="gap-2">
        <Button title={selected.done ? 'Markeer als niet gegeven' : 'Markeer als gegeven'} disabled={pending}
          onPress={() => void toggle(selected).then(saved => { if (saved) setDetailKey(null); })} />
        <Button title="Vraag Shelby" variant="text" onPress={() => { setDetailKey(null); router.push({ pathname: '/nova-chat', params: { horseId, protocolId: protocol.id } }); }} />
      </View> : undefined}>
      {!!selected?.buildupLabel && <Text className="mb-3 text-[14px] leading-[20px] text-ink-70">{selected.buildupLabel}</Text>}
      {!!selected?.instructions && <View className="mb-6 gap-3"><Text className="font-bold text-[19px] text-ink">Instructies</Text><Text className="text-[16px] leading-[26px] text-ink-70">{selected.instructions}</Text></View>}
      {!!selected?.description && <View className="gap-3"><Text className="font-bold text-[19px] text-ink">Over dit product</Text><Text className="text-[16px] leading-[26px] text-ink-70">{selected.description}</Text></View>}
      {!selected?.instructions && !selected?.description && <Text className="text-[15px] leading-[24px] text-ink-70">Er is voor dit product nog geen toelichting toegevoegd.</Text>}
    </Sheet>
  </>;
}

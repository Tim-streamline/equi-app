import { useEffect, useRef, useState, type ComponentType, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import Svg, { Circle } from 'react-native-svg';
import { Check, ChevronLeft, ChevronRight, ShoppingBag } from 'lucide-react-native';
import { Button } from '@/components/ui/Button';
import { useSupplementIntakesForDate, useStoreMutations } from '@/db/hooks';
import { dashboardRequest, deviceTimezone } from '@/hooks/useHorseDashboard';
import type { DashboardPhase, DashboardProtocol, DashboardSupplement } from '@/lib/horse-dashboard';
import { administrationKey, todayGroups, PHASE_COLORS } from '@/lib/protocol-today';
import { calendarDate, dayProgress, nextPhaseStart, phaseStarts, shortDate, weekPhaseLines } from '@/lib/protocol-calendar';
import { selectionHaptic } from '@/lib/haptics';

type SheetProps = { visible: boolean; title: string; subtitle?: string; children: ReactNode; onClose: () => void; footer?: ReactNode };
type ProtocolDay = { date: string; label: string; editable: boolean; editableUntil?: string; weekNumber?: number; items: DashboardSupplement[]; state: 'default' | 'complete' | 'partial' | 'missed' };

export function DayRing({ progress, size = 40 }: { progress: number | null; size?: number }) {
  if (progress == null) return null;
  const radius = 18, length = 2 * Math.PI * radius;
  return <Svg width={size} height={size} viewBox="0 0 40 40" style={{ position: 'absolute' }} pointerEvents="none">
    <Circle cx="20" cy="20" r={radius} stroke="#E6E5E1" strokeWidth="3" fill="none" />
    {progress > 0 && <Circle cx="20" cy="20" r={radius} stroke="#18BAB0" strokeWidth="3" fill="none" strokeLinecap="round"
      strokeDasharray={`${length * progress} ${length}`} rotation="-90" origin="20, 20" />}
  </Svg>;
}

export function ProtocolCalendar({ protocol, today, horseId, onMonth, onPhase, onOrder, onRefresh, SheetComponent: Sheet }: {
  protocol: DashboardProtocol; today: string; horseId: string; onMonth: (month: string) => void;
  onPhase: (phase: DashboardPhase) => void; onOrder: (phaseIds?: string[]) => void;
  onRefresh: () => Promise<void>; SheetComponent: ComponentType<SheetProps>;
}) {
  const [selected, setSelected] = useState<string>();
  const [day, setDay] = useState<ProtocolDay | null>(null);
  const [detail, setDetail] = useState<string | null>(null);
  const [dayError, setDayError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [choices, setChoices] = useState<DashboardPhase[]>([]);
  const [overrides, setOverrides] = useState<Record<string, ProtocolDay>>({});
  const request = useRef(0), saving = useRef(false);
  const local = useSupplementIntakesForDate(today);
  const mutations = useStoreMutations();
  useEffect(() => () => { request.current++; }, []);
  const closeDay = () => { request.current++; setSelected(undefined); setDay(null); setDetail(null); setDayError(null); setBusy(false); };
  const openDay = async (date: string) => {
    const generation = ++request.current;
    setSelected(date); setDay(null); setDetail(null); setDayError(null); setBusy(true);
    try {
      const query = new URLSearchParams({ protocol_id: protocol.id, date, timezone: deviceTimezone() });
      const result = await dashboardRequest(`/api/horses/${horseId}/protocol-day?${query}`) as ProtocolDay;
      if (generation === request.current) setDay(result);
    } catch { if (generation === request.current) setDayError('De daglijst kon niet worden geladen. Probeer het opnieuw.'); }
    finally { if (generation === request.current) setBusy(false); }
  };
  const toggle = async (item: DashboardSupplement) => {
    if (!day?.editable || saving.current || busy) return;
    saving.current = true;
    selectionHaptic();
    const generation = ++request.current;
    setBusy(true); setDayError(null);
    try {
      if (day.date === today) {
        await mutations.toggleSupplementIntake(item.id, item.dosage, today, horseId, item.administration ?? 1);
        void onRefresh();
        return;
      }
      const result = await dashboardRequest(`/api/horses/${horseId}/protocol-day`, {
        protocol_id: protocol.id, date: day.date, item_id: item.id, administration: item.administration ?? 1, done: !item.done, timezone: deviceTimezone(),
      }) as ProtocolDay;
      if (generation !== request.current) return;
      setDay(result); setOverrides(previous => ({ ...previous, [result.date]: result }));
      void onRefresh();
    } catch { if (generation === request.current) setDayError('Niet opgeslagen. Controleer je verbinding en probeer opnieuw.'); }
    finally { saving.current = false; if (generation === request.current) setBusy(false); }
  };
  const showPhases = (phases: DashboardPhase[]) => { if (phases.length === 1) onPhase(phases[0]); else setChoices(phases); };
  const next = nextPhaseStart(protocol, today);
  const todayItems = todayGroups(protocol.today.items, local).flatMap(group => group.items);
  const shownDay = day?.date === today ? { ...day, items: todayItems } : day;
  const selectedItem = shownDay?.items.find(item => administrationKey(item) === detail);
  const groups = todayGroups(shownDay?.items ?? [], []);
  const openToday = () => {
    request.current++; setDetail(null); setDayError(null); setBusy(false); setSelected(today);
    const until = new Date(today + 'T00:00:00Z'); until.setUTCDate(until.getUTCDate() + 7);
    setDay({ date: today, label: shortDate(today, { weekday: 'long', day: 'numeric', month: 'long' }), editable: true,
      weekNumber: protocol.currentWeek, editableUntil: until.toISOString().slice(0,10), items: todayItems, state: 'default' });
  };
  const calendar = protocol.calendar;
  useEffect(() => { setOverrides({}); }, [calendar]);
  return <>
    {protocol.preparing && <View className="mb-5 gap-2"><Text className="font-bold text-[20px] text-ink">Nog niet gestart</Text><Text className="text-[15px] leading-[23px] text-ink-70">Je planning begint zodra je bij Vandaag je protocol start.</Text></View>}
    {!!next.length && <Pressable accessibilityRole="button" accessibilityLabel="Bestellijst voor de volgende fases" onPress={() => onOrder(next.map(phase => phase.id))} className="mb-6 flex-row items-center gap-3 rounded-[22px] bg-mint-50 p-4">
      <ShoppingBag size={24} color="#106E6D" />
      <View className="flex-1 gap-1"><Text className="font-semi text-[15px] leading-[22px] text-teal-700">{shortDate(calendarDate(next[0].startsAt))} {next.length === 1 ? 'start een nieuwe fase' : `starten ${next.length} nieuwe fases`}</Text><Text className="text-[14px] leading-[21px] text-ink-70">Bekijk de bestellijst voor de volgende fases.</Text></View>
      <ChevronRight size={20} color="#106E6D" />
    </Pressable>}
    <View className="mb-4 flex-row items-center justify-between">
      <Pressable accessibilityRole="button" accessibilityLabel="Vorige maand" onPress={() => onMonth(calendar.previousMonth)} className="min-h-[44px] min-w-[44px] items-center justify-center"><ChevronLeft size={23} color="#1B2A2A" /></Pressable>
      <Text accessibilityRole="header" className="font-semi text-[20px] text-ink">{calendar.label}</Text>
      <Pressable accessibilityRole="button" accessibilityLabel="Volgende maand" onPress={() => onMonth(calendar.nextMonth)} className="min-h-[44px] min-w-[44px] items-center justify-center"><ChevronRight size={23} color="#1B2A2A" /></Pressable>
    </View>
    <View className="mb-2 flex-row">{['MA','DI','WO','DO','VR','ZA','ZO'].map(label => <Text key={label} className="flex-1 text-center font-semi text-[12px] text-ink-50">{label}</Text>)}</View>
    <View>
      {Array.from({ length: Math.ceil(calendar.cells.length / 7) }, (_, row) => {
        const cells = calendar.cells.slice(row * 7, row * 7 + 7);
        const lines = weekPhaseLines(cells, protocol.phases, !!protocol.preparing);
        return <View key={row} className="mb-2">
        <View className="flex-row">
        {cells.map((cell, index) => {
        const changed = cell ? overrides[cell.date] : null;
        const isToday = !!cell?.isToday && !protocol.preparing;
        const counts = isToday ? { total: todayItems.length, done: todayItems.filter(item => item.done).length, state: cell?.state ?? 'default' }
          : changed ? { total: changed.items.length, done: changed.items.filter(item => item.done).length, state: changed.state } : cell;
        const progress = !protocol.preparing && cell && cell.date <= today && counts ? dayProgress(counts) : null;
        const starts = cell ? phaseStarts(protocol, cell.date) : [];
        const available = !!cell && (isToday || cell.available || starts.length > 0);
        return <View key={cell?.date ?? `blank-${index}`} style={{ width: '14.285714%', minHeight: 64 }} className="items-center justify-center">
          {cell && <>
            <Pressable accessibilityRole="button" accessibilityLabel={`${shortDate(cell.date, { weekday: 'long', day: 'numeric', month: 'long' })}${progress != null ? `, ${Math.round(progress * 100)}% afgevinkt` : ''}${starts.length ? `, ${starts.length} fase${starts.length > 1 ? 's' : ''} start` : ''}`}
              disabled={!available} accessibilityState={{ selected: selected === cell.date, disabled: !available }}
              onPress={() => isToday ? openToday() : cell.available ? void openDay(cell.date) : showPhases(starts)}
              className="h-11 w-full max-w-[44px] items-center justify-center">
              <DayRing progress={progress} />
              <View className={`h-8 w-8 items-center justify-center rounded-full ${isToday ? 'bg-teal-900' : ''}`}>
                <Text className={`text-[17px] ${isToday ? 'font-bold text-white' : progress != null ? 'font-semi text-ink' : 'text-ink-50'}`}>{cell.day}</Text>
              </View>
            </Pressable>

          </>}
        </View>;
        })}
        </View>
        {!protocol.preparing && <View className="gap-1" style={{ paddingBottom: 4 }}>
          {lines.map(line => <View key={line.phaseId} style={{ height: 3, position: 'relative' }}>
            {line.length > 0 && <View style={{ position: 'absolute', left: `${line.start / 7 * 100}%`, width: `${line.length / 7 * 100}%`, height: 3, borderRadius: 3, backgroundColor: PHASE_COLORS[line.lane % PHASE_COLORS.length] }} />}
          </View>)}
        </View>}
        </View>;
      })}
    </View>
    <View className="mb-5 mt-4 gap-3 border-t border-ink-8 pt-4">
      <View className="flex-row items-center gap-2"><View className="h-5 w-5 items-center justify-center"><DayRing size={18} progress={.65} /></View><Text className="flex-1 text-[14px] text-ink-70">De ring laat zien hoeveel je die dag hebt afgevinkt.</Text></View>
    </View>
    <Text className="mb-2 font-semi text-[15px] text-ink">Fases van je protocol</Text>
    {protocol.phases.map((phase, index) => <Pressable key={phase.id} accessibilityRole="button" accessibilityLabel={`Bekijk ${phase.title}`} onPress={() => onPhase(phase)} className="min-h-[56px] flex-row items-center gap-3 border-b border-ink-8 py-3">
      <View style={{ width: 4, alignSelf: 'stretch', borderRadius: 4, backgroundColor: PHASE_COLORS[index % PHASE_COLORS.length] }} /><View className="flex-1 gap-1"><Text className="font-semi text-[16px] text-ink">{phase.title}</Text><Text className="text-[13px] text-ink-70">{phase.weekLabel} · {phase.state === 'active' ? 'Nu actief' : phase.statusLabel}</Text></View><Text className="font-semi text-[14px] text-teal-600">Uitleg</Text><ChevronRight size={17} color="#106E6D" />
    </Pressable>)}
    <Sheet visible={!!selected} title={selectedItem?.name ?? day?.label ?? (selected ? shortDate(selected, { weekday: 'long', day: 'numeric', month: 'long' }) : '')}
      subtitle={selectedItem ? selectedItem.dosage ?? undefined : day ? [day.weekNumber ? `Week ${day.weekNumber}` : null, day.editable ? (day.editableUntil ? 'Af te vinken t/m ' + shortDate(day.editableUntil) : 'Je kunt deze dag tot 7 dagen terug aanpassen.') : 'Alleen te bekijken'].filter(Boolean).join(' · ') : undefined}
      onClose={closeDay}>
      {selectedItem ? <View className="gap-5">
        <Button title="Terug naar daglijst" variant="text" onPress={() => setDetail(null)} />
        {!!selectedItem.instructions && <View className="gap-2"><Text className="font-semi text-[16px] leading-[22px] text-ink">Instructies</Text><Text className="font-sans text-[15px] leading-[24px] text-ink-70">{selectedItem.instructions}</Text></View>}
        {!!selectedItem.description && <View className="gap-2"><Text className="font-semi text-[16px] leading-[22px] text-ink">Over dit product</Text><Text className="font-sans text-[15px] leading-[24px] text-ink-70">{selectedItem.description}</Text></View>}
        {!selectedItem.instructions && !selectedItem.description && <Text className="text-ink-70">Er is voor dit product nog geen toelichting toegevoegd.</Text>}
      </View> : <>
        {busy && !day && <ActivityIndicator color="#18BAB0" />}
        {!!dayError && <Text accessibilityRole="alert" className="mb-3 text-[14px] text-[#8B621C]">{dayError}</Text>}
        {!day && !busy && selected && <Button title="Opnieuw proberen" onPress={() => void openDay(selected)} />}
        {day?.items.length === 0 && <Text className="py-4 text-ink-70">Op deze dag stond niets gepland.</Text>}
        {groups.map(group => <View key={group.label} className="mb-4">
          <View className="mb-1 flex-row items-center justify-between"><Text className="font-semi text-[16px] leading-[22px] text-ink">{group.label}</Text><Text className="font-sans text-[13px] leading-[20px] text-ink-70">{group.done} van {group.items.length}</Text></View>
          {group.items.map(item => <View key={administrationKey(item)} className="flex-row items-center gap-2">
            <Pressable accessibilityRole="checkbox" accessibilityLabel={`${item.name}, ${group.label}, ${item.dosage ?? ''}`} accessibilityState={{ checked: !!item.done, disabled: busy || !day?.editable }} disabled={busy || !day?.editable} onPress={() => void toggle(item)} className="min-h-[48px] min-w-[44px] items-center justify-center">
              <View className={`h-7 w-7 items-center justify-center rounded-full border-2 ${item.done ? 'border-mint-500 bg-mint-500' : 'border-ink-30'}`}>{item.done && <Check size={17} color="white" />}</View>
            </Pressable>
            <View className="flex-1 flex-row items-center gap-2 border-b border-ink-8 py-3"><Text className="flex-1 font-sans text-[16px] leading-[23px] text-ink"><Text className="font-semi">{item.name}</Text>{'  '}<Text className="text-ink-70">{item.dosage}</Text></Text><Pressable accessibilityRole="button" accessibilityLabel={`Uitleg over ${item.name}, ${group.label}`} onPress={() => setDetail(administrationKey(item))} className="min-h-[44px] flex-row items-center gap-1"><Text className="font-semi text-[13px] text-teal-600">Uitleg</Text><ChevronRight size={17} color="#106E6D" /></Pressable></View>
          </View>)}
        </View>)}
      </>}
    </Sheet>
    <Sheet visible={choices.length > 0} title="Volgende fases" subtitle={choices[0]?.startsAt ? 'Start ' + shortDate(calendarDate(choices[0].startsAt)) : undefined} onClose={() => setChoices([])} footer={<Button title="Bekijk bestellijst" onPress={() => { const ids = choices.map(phase => phase.id); setChoices([]); onOrder(ids); }} />}>
      {choices.map(phase => <Pressable key={phase.id} accessibilityRole="button" onPress={() => { setChoices([]); onPhase(phase); }} className="min-h-[56px] flex-row items-center justify-between gap-3 border-b border-ink-8 py-4"><Text className="flex-1 font-semi text-[18px] text-ink">{phase.title}</Text><Text className="font-semi text-teal-600">Uitleg</Text><ChevronRight size={18} color="#106E6D" /></Pressable>)}
    </Sheet>
  </>;
}

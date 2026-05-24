import { useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { MoreHorizontal, Check, X, Circle, Plus, ChevronLeft, ChevronRight, Leaf, Footprints, PawPrint } from 'lucide-react-native';
import { AppHeader } from '@/components/ui/AppHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Button } from '@/components/ui/Button';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useActiveProtocolForHorse,
  useAllTaskCompletions,
  useCurrentUser,
  usePhaseItems,
  useProtocolAnalysis,
  useProtocolPhases,
  useProtocolTasks,
  useStoreMutations,
  useValue,
  useCurrentHorseId,
} from '@/db/hooks';

type Tab = 'protocol' | 'kalender' | 'analyse';

const ADVICE_ICONS: Record<string, (props: { size: number; color: string }) => any> = {
  leaf: (p) => <Leaf {...p} />,
  run: (p) => <Footprints {...p} />,
  horse: (p) => <PawPrint {...p} />,
};

export default function ProtocolListScreen() {
  const [tab, setTab] = useState<Tab>('protocol');
  const padBottom = useTabBarPadding();
  const protocol = useActiveProtocolForHorse();
  const user = useCurrentUser();

  if (!protocol) {
    return (
      <View className="flex-1 bg-canvas">
        <SafeAreaView edges={['top']} style={{ flex: 1 }}>
          <AppHeader greet="Protocol" title="Jouw plan" avatar={(user.avatarInitial as string) ?? 'M'} />
        </SafeAreaView>
      </View>
    );
  }

  const sub =
    tab === 'protocol'
      ? (protocol.subtitleProtocol as string)
      : tab === 'kalender'
        ? (protocol.subtitleCalendar as string)
        : (protocol.subtitleAnalyse as string);

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <AppHeader
            greet="Protocol"
            title="Jouw plan"
            avatar={(user.avatarInitial as string) ?? 'M'}
            right={
              <IconButton>
                <MoreHorizontal size={20} color="#1B2A2A" />
              </IconButton>
            }
          />
          <View className="mx-4 mb-4 rounded-2xl bg-white border border-ink-8 overflow-hidden shadow-sm">
            <View className="p-4 pb-3">
              <Text className="font-bold text-ink" style={{ fontSize: 22, lineHeight: 26 }}>
                {protocol.title as string}
              </Text>
              <Text className="mt-1 text-[13px] text-ink-50">{sub}</Text>
            </View>
            <View className="flex-row border-t border-ink-8">
              {(['protocol', 'kalender', 'analyse'] as Tab[]).map((t) => {
                const active = tab === t;
                return (
                  <Pressable
                    key={t}
                    onPress={() => setTab(t)}
                    className="flex-1 items-center py-3"
                    style={{
                      borderBottomWidth: 2,
                      borderBottomColor: active ? '#18BAB0' : 'transparent',
                    }}
                  >
                    <Text
                      className={`font-semi text-[13px] ${active ? 'text-mint-700' : 'text-ink-50'}`}
                      style={{ textTransform: 'capitalize' }}
                    >
                      {t}
                    </Text>
                  </Pressable>
                );
              })}
            </View>
          </View>

          {tab === 'protocol' && <ProtocolPhasesView protocolId={protocol.id} />}
          {tab === 'kalender' && <ProtocolCalendar protocolId={protocol.id} />}
          {tab === 'analyse' && <ProtocolAnalyseView protocolId={protocol.id} />}
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

function ProtocolPhasesView({ protocolId }: { protocolId: string }) {
  const phases = useProtocolPhases(protocolId);
  return (
    <View className="px-4 gap-2.5">
      {phases.map((p: any) => (
        <PhaseCard key={p.id} phase={p} />
      ))}
    </View>
  );
}

function PhaseCard({ phase }: { phase: any }) {
  const items = usePhaseItems(phase.id);
  const tone =
    phase.state === 'done'
      ? { border: 'border-mint-200', chipBg: 'bg-mint-50', chipText: 'text-mint-700' }
      : phase.state === 'active'
        ? { border: 'border-mint-500', chipBg: 'bg-mint-500', chipText: 'text-white' }
        : { border: 'border-ink-8', chipBg: 'bg-ink-8', chipText: 'text-ink-70' };
  return (
    <View className={`rounded-2xl border-2 bg-white p-4 ${tone.border}`}>
      <View className="flex-row items-start justify-between">
        <Text className="flex-1 font-bold text-ink text-[15px] pr-3">{phase.title}</Text>
        <View className={`flex-row items-center gap-1 rounded-pill px-2.5 py-1 ${tone.chipBg}`}>
          {phase.state === 'done' && <Check size={11} color="#108A82" strokeWidth={3} />}
          <Text className={`font-semi text-[11px] ${tone.chipText}`}>{phase.chipLabel}</Text>
        </View>
      </View>
      {items.length > 0 && (
        <View className="mt-3 gap-1.5">
          {items.map((it: any) => (
            <View key={it.id} className="flex-row items-start gap-2">
              <View className="mt-2 h-1 w-1 rounded-full bg-mint-500" />
              <Text className="flex-1 text-[13.5px] text-ink-70 leading-[20px]">{it.label}</Text>
            </View>
          ))}
        </View>
      )}
    </View>
  );
}

function ProtocolAnalyseView({ protocolId }: { protocolId: string }) {
  const analysis = useProtocolAnalysis(protocolId);
  if (!analysis) return null;
  return (
    <View className="px-4">
      <View className="mb-4 rounded-2xl bg-mint-50 p-4">
        <Text className="font-semi uppercase text-mint-700" style={{ fontSize: 10, letterSpacing: 1.2 }}>
          Waarschijnlijkste oorzaak
        </Text>
        <Text className="mt-2 text-[15px] text-ink leading-[22px]">{analysis.cause}</Text>
      </View>
      <Text className="font-bold text-ink mb-2 px-1" style={{ fontSize: 16 }}>
        Advies
      </Text>
      <View className="gap-2">
        {analysis.advice.map((a: any) => {
          const Icon = ADVICE_ICONS[a.iconKey];
          return (
            <View key={a.id} className="flex-row gap-3 rounded-2xl border border-ink-8 bg-white p-4">
              <View className="h-9 w-9 items-center justify-center rounded-xl bg-mint-50">
                {Icon && Icon({ size: 18, color: '#0D5C5B' })}
              </View>
              <View className="flex-1">
                <Text className="font-bold text-ink text-[15px]">{a.title}</Text>
                <Text className="mt-1 text-[13px] text-ink-50 leading-[18px]">{a.body}</Text>
              </View>
            </View>
          );
        })}
      </View>
    </View>
  );
}

const DOW = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];

type CalCell = { d: number; s: 'done' | 'today' | 'upcoming' | 'empty' };

const CALENDAR_WEEKS: (CalCell | null)[][] = [
  [{ d: 1, s: 'done' }, { d: 2, s: 'done' }, { d: 3, s: 'done' }, { d: 4, s: 'done' }, { d: 5, s: 'done' }, { d: 6, s: 'done' }, { d: 7, s: 'done' }],
  [{ d: 8, s: 'done' }, { d: 9, s: 'done' }, { d: 10, s: 'today' }, { d: 11, s: 'upcoming' }, { d: 12, s: 'upcoming' }, { d: 13, s: 'upcoming' }, { d: 14, s: 'upcoming' }],
  [{ d: 15, s: 'upcoming' }, { d: 16, s: 'upcoming' }, { d: 17, s: 'upcoming' }, { d: 18, s: 'upcoming' }, { d: 19, s: 'upcoming' }, { d: 20, s: 'upcoming' }, { d: 21, s: 'empty' }],
  [{ d: 22, s: 'empty' }, { d: 23, s: 'empty' }, { d: 24, s: 'empty' }, { d: 25, s: 'empty' }, { d: 26, s: 'empty' }, { d: 27, s: 'empty' }, { d: 28, s: 'empty' }],
  [{ d: 29, s: 'empty' }, { d: 30, s: 'empty' }, { d: 31, s: 'empty' }, null, null, null, null],
];

// The calendar grid above is fixed to this month. "Today" only highlights when
// the real current date falls within it (1-indexed month).
const CALENDAR_MONTH = { year: 2026, month: 5 };

function dateForDay(day: number) {
  const { year, month } = CALENDAR_MONTH;
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function ProtocolCalendar({ protocolId }: { protocolId: string }) {
  const monthLabel = useValue('currentMonthLabel') as string;
  const now = new Date();
  const isCurrentMonth =
    now.getFullYear() === CALENDAR_MONTH.year && now.getMonth() + 1 === CALENDAR_MONTH.month;
  // -1 never matches a day cell, so no cell highlights as "today" off-month.
  const todayDay = isCurrentMonth ? now.getDate() : -1;
  const horseId = useCurrentHorseId();
  const tasks = useProtocolTasks(protocolId);
  const allCompletions = useAllTaskCompletions();
  const mutations = useStoreMutations();

  const [selected, setSelected] = useState<number>(isCurrentMonth ? now.getDate() : 1);

  const completionsByDay = useMemo(() => {
    const map: Record<number, Record<string, boolean>> = {};
    allCompletions.forEach((c: any) => {
      const m = c.date?.match(/2026-05-(\d{2})/);
      if (!m) return;
      const day = parseInt(m[1], 10);
      if (!map[day]) map[day] = {};
      map[day][c.taskId] = !!c.done;
    });
    return map;
  }, [allCompletions]);

  const cellState = (cell: CalCell): string => {
    if (cell.d === selected) return 'selected';
    if (cell.d === todayDay) return 'today';
    const c = completionsByDay[cell.d];
    if (c && Object.keys(c).length > 0) {
      const values = Object.values(c);
      if (values.every(Boolean)) return 'done';
      if (values.some(Boolean)) return 'partial';
    }
    return cell.s;
  };

  const cellStyle = (state: string) => {
    switch (state) {
      case 'selected':
        return { bg: '#0D5C5B', text: '#fff', border: '#0D5C5B' };
      case 'today':
        return { bg: '#18BAB0', text: '#fff', border: '#18BAB0' };
      case 'done':
        return { bg: '#EAFBF9', text: '#108A82', border: '#C9F3EE' };
      case 'partial':
        return { bg: 'transparent', text: '#0D5C5B', border: '#5FD7CB' };
      case 'upcoming':
        return { bg: 'transparent', text: '#1B2A2A', border: 'rgba(27,42,42,0.08)' };
      default:
        return { bg: 'transparent', text: 'rgba(27,42,42,0.3)', border: 'rgba(27,42,42,0.04)' };
    }
  };

  const selectedDate = dateForDay(selected);
  const dayCompletions = completionsByDay[selected] ?? {};
  const doneCount = tasks.filter((t: any) => dayCompletions[t.id]).length;

  return (
    <>
      <View className="mx-4 mb-4 rounded-2xl border border-ink-8 bg-white p-4">
        <View className="mb-3 flex-row items-center justify-between">
          <IconButton><ChevronLeft size={18} color="#1B2A2A" /></IconButton>
          <Text className="font-bold text-ink text-[15px]">{monthLabel}</Text>
          <IconButton><ChevronRight size={18} color="#1B2A2A" /></IconButton>
        </View>
        <View className="flex-row mb-2">
          {DOW.map((d) => (
            <Text
              key={d}
              className="flex-1 text-center font-semi text-ink-50"
              style={{ fontSize: 10, letterSpacing: 0.8 }}
            >
              {d}
            </Text>
          ))}
        </View>
        <View className="flex-row flex-wrap">
          {CALENDAR_WEEKS.flatMap((wk, wi) =>
            wk.map((cell, ci) => {
              const key = `${wi}-${ci}`;
              if (!cell) {
                return <View key={key} style={{ width: `${100 / 7}%`, aspectRatio: 1 }} />;
              }
              const state = cellState(cell);
              const s = cellStyle(state);
              const onDark = state === 'selected' || state === 'today';
              // One mark per protocol item for this day:
              //  • done   → green check (item was ticked)
              //  • open   → hollow ring (still to do, on today/future dates)
              //  • missed → red cross (left unchecked on a past date)
              const dayC = completionsByDay[cell.d] ?? {};
              // Off-month (todayDay < 0) has no live "today", so treat every cell
              // as past: show only recorded completions, no open rings.
              const ref = todayDay > 0 ? todayDay : Infinity;
              const marks = tasks
                .map((t: any): 'done' | 'open' | 'missed' | null => {
                  const recorded = t.id in dayC;
                  if (recorded && dayC[t.id]) return 'done';
                  if (cell.d >= ref) return 'open'; // today or future → still open
                  return recorded ? 'missed' : null; // past: missed if logged unticked
                })
                .filter((m): m is 'done' | 'open' | 'missed' => m !== null);
              return (
                <View key={key} style={{ width: `${100 / 7}%`, aspectRatio: 1, padding: 3 }}>
                  <Pressable
                    onPress={() => setSelected(cell.d)}
                    className="flex-1 items-center justify-center rounded-xl"
                    style={{ backgroundColor: s.bg, borderWidth: 1, borderColor: s.border }}
                  >
                    <Text className="font-semi text-[13px]" style={{ color: s.text }}>
                      {cell.d}
                    </Text>
                    {marks.length > 0 && (
                      <View
                        className="flex-row flex-wrap items-center justify-center"
                        style={{ maxWidth: 22, marginTop: 1, gap: 1 }}
                      >
                        {marks.map((m, mi) =>
                          m === 'done' ? (
                            <Check key={mi} size={9} strokeWidth={3.5} color={onDark ? '#5FD7CB' : '#2EA875'} />
                          ) : m === 'missed' ? (
                            <X key={mi} size={9} strokeWidth={3.5} color={onDark ? '#F2B8AC' : '#C2543E'} />
                          ) : (
                            <Circle
                              key={mi}
                              size={9}
                              strokeWidth={2.5}
                              color={onDark ? 'rgba(255,255,255,0.6)' : 'rgba(27,42,42,0.35)'}
                            />
                          ),
                        )}
                      </View>
                    )}
                  </Pressable>
                </View>
              );
            }),
          )}
        </View>
        <View className="mt-3 flex-row gap-3">
          {[
            { label: 'Gedaan', bg: '#EAFBF9', border: '#C9F3EE' },
            { label: 'Vandaag', bg: '#18BAB0', border: '#18BAB0' },
            { label: 'Deels', bg: 'transparent', border: '#5FD7CB' },
          ].map((l) => (
            <View key={l.label} className="flex-row items-center gap-1.5">
              <View
                className="rounded"
                style={{ width: 10, height: 10, backgroundColor: l.bg, borderWidth: 1, borderColor: l.border }}
              />
              <Text className="text-[11px] text-ink-50">{l.label}</Text>
            </View>
          ))}
        </View>
      </View>

      <View className="mx-4 rounded-2xl border border-ink-8 bg-white p-4">
        <View className="mb-3">
          <Text className="font-bold text-ink text-[15px]">
            {selected === todayDay ? 'Vandaag · ' : ''}
            {selected} mei
          </Text>
          <Text className="mt-0.5 text-[12px] text-ink-50">
            {doneCount} van {tasks.length} afgevinkt
          </Text>
        </View>
        <View className="gap-2">
          {tasks.map((t: any) => {
            const done = !!dayCompletions[t.id];
            return (
              <Pressable
                key={t.id}
                onPress={() => mutations.toggleTaskCompletion(t.id, selectedDate, horseId)}
                className="flex-row items-center gap-3 rounded-xl border border-ink-8 bg-canvas p-3"
              >
                <View
                  className={`h-5 w-5 items-center justify-center rounded-full border-2 ${
                    done ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
                  }`}
                >
                  {done && <Check size={12} color="#fff" strokeWidth={3} />}
                </View>
                <Text className={`flex-1 text-[14px] ${done ? 'text-ink-50 line-through' : 'text-ink'}`}>
                  {t.label}
                </Text>
              </Pressable>
            );
          })}
        </View>
        <View className="mt-3">
          <Button
            variant="ghost"
            onPress={() => router.push('/(tabs)/protocol/log-entry')}
            leading={<Plus size={18} color="#1B2A2A" />}
            title="Voeg observatie toe"
          />
        </View>
      </View>
    </>
  );
}

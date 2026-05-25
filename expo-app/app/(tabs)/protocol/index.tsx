import { useEffect, useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
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
  useCurrentHorseId,
} from '@/db/hooks';

type Tab = 'protocol' | 'kalender' | 'analyse';

const ADVICE_ICONS: Record<string, (props: { size: number; color: string }) => any> = {
  leaf: (p) => <Leaf {...p} />,
  run: (p) => <Footprints {...p} />,
  horse: (p) => <PawPrint {...p} />,
};

function isTab(v: unknown): v is Tab {
  return v === 'protocol' || v === 'kalender' || v === 'analyse';
}

export default function ProtocolListScreen() {
  // `tab` selects the initial sub-tab (e.g. opened as Kalender from Home); `t`
  // is a nonce so repeat navigations with the same tab still re-apply it.
  const { tab: tabParam, t: tabNonce } = useLocalSearchParams<{ tab?: string; t?: string }>();
  const [tab, setTab] = useState<Tab>(isTab(tabParam) ? tabParam : 'protocol');
  useEffect(() => {
    if (isTab(tabParam)) setTab(tabParam);
  }, [tabParam, tabNonce]);
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
const MONTHS_NL = [
  'Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni',
  'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December',
];
const MONTHS_SHORT = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

function toIso(year: number, month0: number, day: number) {
  return `${year}-${String(month0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function ProtocolCalendar({ protocolId }: { protocolId: string }) {
  const horseId = useCurrentHorseId();
  const tasks = useProtocolTasks(protocolId);
  const allCompletions = useAllTaskCompletions();
  const mutations = useStoreMutations();

  const todayIso = useMemo(() => {
    const n = new Date();
    return toIso(n.getFullYear(), n.getMonth(), n.getDate());
  }, []);

  // First-of-month for the month currently in view; starts on the real month.
  const [view, setView] = useState(() => {
    const n = new Date();
    return new Date(n.getFullYear(), n.getMonth(), 1);
  });
  const viewYear = view.getFullYear();
  const viewMonth0 = view.getMonth();

  const [selected, setSelected] = useState<string>(todayIso);

  const changeMonth = (delta: number) => {
    const next = new Date(viewYear, viewMonth0 + delta, 1);
    setView(next);
    // Land on today if it falls in the new month, otherwise its first day.
    const n = new Date();
    const sameMonth = n.getFullYear() === next.getFullYear() && n.getMonth() === next.getMonth();
    setSelected(sameMonth ? todayIso : toIso(next.getFullYear(), next.getMonth(), 1));
  };

  // Completions keyed by full ISO date so the lookup works for any month.
  const completionsByDate = useMemo(() => {
    const map: Record<string, Record<string, boolean>> = {};
    allCompletions.forEach((c: any) => {
      if (!c.date) return;
      (map[c.date] ??= {})[c.taskId] = !!c.done;
    });
    return map;
  }, [allCompletions]);

  // Month grid: leading blanks (Monday-first) + each day + trailing blanks.
  const cells = useMemo<(number | null)[]>(() => {
    const leading = (new Date(viewYear, viewMonth0, 1).getDay() + 6) % 7;
    const daysInMonth = new Date(viewYear, viewMonth0 + 1, 0).getDate();
    const out: (number | null)[] = [
      ...Array.from({ length: leading }, () => null),
      ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
    ];
    while (out.length % 7 !== 0) out.push(null);
    return out;
  }, [viewYear, viewMonth0]);

  const cellStyle = (state: string) => {
    switch (state) {
      case 'selected':
        return { bg: '#0D5C5B', text: '#fff', border: '#0D5C5B' };
      case 'today':
        // White fill with the green "deels" outline.
        return { bg: '#FFFFFF', text: '#0D5C5B', border: '#5FD7CB' };
      default:
        // Every other day shares the light-green "gedaan" background; per-item
        // status is conveyed by the check / ring / cross marks inside the cell.
        return { bg: '#EAFBF9', text: '#108A82', border: '#C9F3EE' };
    }
  };

  const [, selMonthStr, selDayStr] = selected.split('-');
  const selDay = Number(selDayStr);
  const selMonth0 = Number(selMonthStr) - 1;
  const dayCompletions = completionsByDate[selected] ?? {};
  const doneCount = tasks.filter((t: any) => dayCompletions[t.id]).length;

  return (
    <>
      <View className="mx-4 mb-4 rounded-2xl border border-ink-8 bg-white p-4">
        <View className="mb-3 flex-row items-center justify-between">
          <IconButton onPress={() => changeMonth(-1)}><ChevronLeft size={18} color="#1B2A2A" /></IconButton>
          <Text className="font-bold text-ink text-[15px]">{`${MONTHS_NL[viewMonth0]} ${viewYear}`}</Text>
          <IconButton onPress={() => changeMonth(1)}><ChevronRight size={18} color="#1B2A2A" /></IconButton>
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
          {cells.map((day, ci) => {
            if (day == null) {
              return <View key={`b-${ci}`} style={{ width: `${100 / 7}%`, aspectRatio: 1 }} />;
            }
            const iso = toIso(viewYear, viewMonth0, day);
            const state = iso === selected ? 'selected' : iso === todayIso ? 'today' : 'default';
            const s = cellStyle(state);
            const onDark = state === 'selected'; // only the deep-teal selected cell is dark
            // One mark per protocol item for this day:
            //  • done   → green check (item was ticked)
            //  • open   → hollow ring (still to do, today/future)
            //  • missed → red cross (left unchecked on a past date)
            const dayC = completionsByDate[iso] ?? {};
            const isTodayOrFuture = iso >= todayIso;
            const marks = tasks
              .map((t: any): 'done' | 'open' | 'missed' | null => {
                const recorded = t.id in dayC;
                if (recorded && dayC[t.id]) return 'done';
                if (isTodayOrFuture) return 'open';
                return recorded ? 'missed' : null; // past: missed if logged unticked
              })
              .filter((m): m is 'done' | 'open' | 'missed' => m !== null);
            return (
              <View key={iso} style={{ width: `${100 / 7}%`, aspectRatio: 1, padding: 3 }}>
                <Pressable
                  onPress={() => setSelected(iso)}
                  className="flex-1 items-center justify-center rounded-xl"
                  style={{ backgroundColor: s.bg, borderWidth: 1, borderColor: s.border }}
                >
                  <Text className="font-semi text-[13px]" style={{ color: s.text }}>
                    {day}
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
          })}
        </View>
        <View className="mt-3 flex-row gap-4">
          <View className="flex-row items-center gap-1.5">
            <Check size={12} strokeWidth={3.5} color="#2EA875" />
            <Text className="text-[11px] text-ink-50">Gedaan</Text>
          </View>
          <View className="flex-row items-center gap-1.5">
            <Circle size={12} strokeWidth={2.5} color="rgba(27,42,42,0.35)" />
            <Text className="text-[11px] text-ink-50">Open</Text>
          </View>
          <View className="flex-row items-center gap-1.5">
            <X size={12} strokeWidth={3.5} color="#C2543E" />
            <Text className="text-[11px] text-ink-50">Gemist</Text>
          </View>
        </View>
      </View>

      <View className="mx-4 rounded-2xl border border-ink-8 bg-white p-4">
        <View className="mb-3">
          <Text className="font-bold text-ink text-[15px]">
            {selected === todayIso ? 'Vandaag · ' : ''}
            {selDay} {MONTHS_SHORT[selMonth0]}
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
                onPress={() => mutations.toggleTaskCompletion(t.id, selected, horseId)}
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

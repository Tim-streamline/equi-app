import { useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { MoreHorizontal, Check, Plus, ChevronLeft, ChevronRight, Leaf, Footprints, PawPrint } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Button } from '@/components/ui/Button';
import {
  PROTOCOL_META,
  PROTOCOL_PHASES,
  PROTOCOL_ANALYSE,
  PROTOCOL_CALENDAR,
  type DayState,
} from '@/data/mock';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';

type Tab = 'protocol' | 'kalender' | 'analyse';

const ADVICE_ICONS: Record<string, (props: { size: number; color: string }) => any> = {
  leaf: (p) => <Leaf {...p} />,
  run: (p) => <Footprints {...p} />,
  horse: (p) => <PawPrint {...p} />,
};

export default function ProtocolListScreen() {
  const [tab, setTab] = useState<Tab>('protocol');
  const padBottom = useTabBarPadding();
  const sub =
    tab === 'protocol'
      ? PROTOCOL_META.subtitleProtocol
      : tab === 'kalender'
        ? PROTOCOL_META.subtitleCalendar
        : PROTOCOL_META.subtitleAnalyse;

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          onBack={() => router.push('/(tabs)/home')}
          right={
            <IconButton>
              <MoreHorizontal size={20} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="mx-4 mb-4 rounded-2xl bg-white border border-ink-8 overflow-hidden shadow-sm">
            <View className="p-4 pb-3">
              <Text className="font-bold text-ink" style={{ fontSize: 22, lineHeight: 26 }}>
                {PROTOCOL_META.horseName}&apos;s plan
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

          {tab === 'protocol' && <ProtocolPhases />}
          {tab === 'kalender' && <ProtocolCalendar />}
          {tab === 'analyse' && (
            <View className="px-4">
              <View className="mb-4 rounded-2xl bg-mint-50 p-4">
                <Text className="font-semi uppercase text-mint-700" style={{ fontSize: 10, letterSpacing: 1.2 }}>
                  Waarschijnlijkste oorzaak
                </Text>
                <Text className="mt-2 text-[15px] text-ink leading-[22px]">{PROTOCOL_ANALYSE.cause}</Text>
              </View>
              <Text className="font-bold text-ink mb-2 px-1" style={{ fontSize: 16 }}>
                Advies
              </Text>
              <View className="gap-2">
                {PROTOCOL_ANALYSE.advice.map((a) => {
                  const Icon = ADVICE_ICONS[a.icon];
                  return (
                    <View key={a.id} className="flex-row gap-3 rounded-2xl border border-ink-8 bg-white p-4">
                      <View className="h-9 w-9 items-center justify-center rounded-xl bg-mint-50">
                        {Icon && Icon({ size: 18, color: '#0D5C5B' })}
                      </View>
                      <View className="flex-1">
                        <Text className="font-bold text-ink text-[15px]">{a.t}</Text>
                        <Text className="mt-1 text-[13px] text-ink-50 leading-[18px]">{a.d}</Text>
                      </View>
                    </View>
                  );
                })}
              </View>
            </View>
          )}
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

function ProtocolPhases() {
  return (
    <View className="px-4 gap-2.5">
      {PROTOCOL_PHASES.map((p) => {
        const tone =
          p.state === 'done'
            ? { border: 'border-mint-200', chipBg: 'bg-mint-50', chipText: 'text-mint-700' }
            : p.state === 'active'
              ? { border: 'border-mint-500', chipBg: 'bg-mint-500', chipText: 'text-white' }
              : { border: 'border-ink-8', chipBg: 'bg-ink-8', chipText: 'text-ink-70' };
        return (
          <View key={p.id} className={`rounded-2xl border-2 bg-white p-4 ${tone.border}`}>
            <View className="flex-row items-start justify-between">
              <Text className="flex-1 font-bold text-ink text-[15px] pr-3">{p.t}</Text>
              <View className={`flex-row items-center gap-1 rounded-pill px-2.5 py-1 ${tone.chipBg}`}>
                {p.state === 'done' && <Check size={11} color="#108A82" strokeWidth={3} />}
                <Text className={`font-semi text-[11px] ${tone.chipText}`}>{p.chip}</Text>
              </View>
            </View>
            {p.items && (
              <View className="mt-3 gap-1.5">
                {p.items.map((it, i) => (
                  <View key={i} className="flex-row items-start gap-2">
                    <View className="mt-2 h-1 w-1 rounded-full bg-mint-500" />
                    <Text className="flex-1 text-[13.5px] text-ink-70 leading-[20px]">{it}</Text>
                  </View>
                ))}
              </View>
            )}
          </View>
        );
      })}
    </View>
  );
}

const DOW = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];

function ProtocolCalendar() {
  const cal = PROTOCOL_CALENDAR;
  const ITEMS = cal.today.items;
  const todayDay = cal.todayDay;

  const initState = useMemo(() => {
    const map: Record<number, boolean[]> = {};
    cal.weeks.flat().forEach((cell) => {
      if (!cell) return;
      if (cell.s === 'done') map[cell.d] = ITEMS.map(() => true);
      else if (cell.s === 'today') map[cell.d] = [true, true, false, false];
      else map[cell.d] = ITEMS.map(() => false);
    });
    return map;
  }, []);

  const [selected, setSelected] = useState(todayDay);
  const [checks, setChecks] = useState<Record<number, boolean[]>>(initState);

  const toggle = (day: number, idx: number) => {
    setChecks((prev) => ({
      ...prev,
      [day]: prev[day].map((v, i) => (i === idx ? !v : v)),
    }));
  };

  const sel = checks[selected] || ITEMS.map(() => false);
  const doneCount = sel.filter(Boolean).length;

  const cellState = (cell: { d: number; s: DayState }): string => {
    if (cell.d === selected) return 'selected';
    if (cell.d === todayDay) return 'today';
    const c = checks[cell.d];
    if (c && c.every(Boolean)) return 'done';
    if (c && c.some(Boolean)) return 'partial';
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

  return (
    <>
      <View className="mx-4 mb-4 rounded-2xl border border-ink-8 bg-white p-4">
        <View className="mb-3 flex-row items-center justify-between">
          <IconButton><ChevronLeft size={18} color="#1B2A2A" /></IconButton>
          <Text className="font-bold text-ink text-[15px]">{cal.monthLabel}</Text>
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
          {cal.weeks.flatMap((wk, wi) =>
            wk.map((cell, ci) => {
              const key = `${wi}-${ci}`;
              if (!cell) {
                return <View key={key} style={{ width: `${100 / 7}%`, aspectRatio: 1 }} />;
              }
              const state = cellState(cell);
              const s = cellStyle(state);
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
                  </Pressable>
                </View>
              );
            })
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
            {doneCount} van {ITEMS.length} afgevinkt
          </Text>
        </View>
        <View className="gap-2">
          {ITEMS.map((it, i) => (
            <Pressable
              key={i}
              onPress={() => toggle(selected, i)}
              className="flex-row items-center gap-3 rounded-xl border border-ink-8 bg-canvas p-3"
            >
              <View
                className={`h-5 w-5 items-center justify-center rounded-full border-2 ${
                  sel[i] ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
                }`}
              >
                {sel[i] && <Check size={12} color="#fff" strokeWidth={3} />}
              </View>
              <Text className={`flex-1 text-[14px] ${sel[i] ? 'text-ink-50 line-through' : 'text-ink'}`}>
                {it}
              </Text>
            </Pressable>
          ))}
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

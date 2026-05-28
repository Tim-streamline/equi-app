// Section overview "hub" — shows progress, auto-save state, and the 10
// sections with their per-section status. Tapping a section pushes the
// section detail screen. Reachable from welcome.tsx or directly from the
// home/protocol-tab entry points when the customer has already started.

import { useEffect, useState } from 'react';
import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  ChevronLeft,
  MoreHorizontal,
  Check,
  Mail,
  AlertTriangle,
  History as HistoryIcon,
  Heart,
  Leaf,
  Droplet,
  Home as HomeIcon,
  Sparkles,
  Camera,
  Send,
} from 'lucide-react-native';

import { IconButton } from '@/components/ui/IconButton';
import {
  INTAKE_SCHEMA,
  Section,
} from '@/lib/intake/schema';
import {
  intakeProgress,
  isSectionComplete,
  nextSectionId,
} from '@/lib/intake/logic';
import { formatSavedAgo, useIntake } from '@/lib/intake/store';

const SECTION_ICONS = {
  mail: Mail,
  horse: Sparkles, // Sparkles substitute — no horse glyph in lucide
  alert: AlertTriangle,
  history: HistoryIcon,
  heart: Heart,
  leaf: Leaf,
  droplet: Droplet,
  home: HomeIcon,
  sparkles: Sparkles,
  camera: Camera,
  send: Send,
} as const;

type Status = 'done' | 'active' | 'todo';

export default function IntakeOverview() {
  const { state } = useIntake();
  const { done, total, pct } = intakeProgress(state.answers);
  const upcomingId = nextSectionId(state.answers);

  // Re-render the "X min geleden" label every minute without blocking input.
  const [, setNow] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setNow((n) => n + 1), 60_000);
    return () => clearInterval(id);
  }, []);
  const saved = formatSavedAgo(state.savedAt);

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <View className="flex-row items-center justify-between gap-3 px-4 pb-3 pt-2">
          <IconButton onPress={() => router.back()}>
            <ChevronLeft size={22} color="#1B2A2A" />
          </IconButton>
          <Text className="flex-1 text-center font-semi text-[17px] text-ink">
            Jouw intake
          </Text>
          <IconButton>
            <MoreHorizontal size={20} color="#1B2A2A" />
          </IconButton>
        </View>

        <ScrollView contentContainerStyle={{ paddingBottom: 100 }}>
          <View className="mx-4 mb-4 overflow-hidden rounded-card bg-teal-700 px-[18px] py-[18px]">
            <View className="flex-row items-start justify-between">
              <View className="flex-1">
                <Text
                  className="font-bold uppercase text-mint-200"
                  style={{ fontSize: 11, letterSpacing: 1.8 }}
                >
                  Protocol-intake
                </Text>
                <Text className="mt-1.5 font-bold text-white" style={{ fontSize: 24, lineHeight: 28 }}>
                  {done} van {total} <Text className="text-mint-300">klaar</Text>
                </Text>
              </View>
              <Text className="font-bold text-mint-300" style={{ fontSize: 22 }}>
                {pct}%
              </Text>
            </View>
            <View className="mt-3.5 h-1 overflow-hidden rounded-pill bg-white/15">
              <View
                className="h-full rounded-pill bg-mint-400"
                style={{ width: `${pct}%` }}
              />
            </View>
            {saved && (
              <View className="mt-2.5 flex-row items-center gap-1.5">
                <View className="h-2 w-2 rounded-full bg-mint-400" />
                <Text className="text-[12px] text-white/65">
                  Automatisch opgeslagen · {saved}
                </Text>
              </View>
            )}
          </View>

          <View className="mx-4 gap-2">
            {INTAKE_SCHEMA.map((s) => {
              const a = state.answers[s.id] ?? {};
              const status: Status =
                isSectionComplete(s, a) && Object.keys(a).length > 0
                  ? 'done'
                  : s.id === upcomingId
                    ? 'active'
                    : 'todo';
              return <SectionRow key={s.id} sec={s} status={status} />;
            })}
          </View>

          <View className="mx-4 mt-4 rounded-2xl bg-mint-50 p-3.5">
            <Text className="text-[12.5px] leading-[18px] text-ink-70">
              <Text className="font-bold text-mint-700">Tip · </Text>
              Doe één sectie per dag als je het rustig wilt aanpakken. Alles wordt automatisch
              opgeslagen — je kunt later verder.
            </Text>
          </View>

          {done === total && (
            <View className="mx-4 mt-3">
              <Pressable
                onPress={() => router.push('/intake/submit' as any)}
                className="items-center rounded-2xl bg-mint-500 py-4"
              >
                <Text className="font-bold text-white text-[15px]">
                  Controleren & versturen →
                </Text>
              </Pressable>
            </View>
          )}
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

function SectionRow({ sec, status }: { sec: Section; status: Status }) {
  const Icon = SECTION_ICONS[sec.icon];
  const active = status === 'active';
  const done = status === 'done';

  return (
    <Pressable
      onPress={() =>
        router.push({ pathname: '/intake/section/[id]', params: { id: sec.id } } as any)
      }
      className={`flex-row items-center gap-3.5 rounded-2xl p-3.5 ${
        done
          ? 'bg-mint-50 border border-mint-100'
          : active
            ? 'bg-white border-2 border-mint-500'
            : 'bg-white border border-ink-8'
      }`}
    >
      <View
        className={`h-10 w-10 items-center justify-center rounded-xl ${
          done ? 'bg-mint-500' : 'bg-mint-50'
        }`}
      >
        {done ? (
          <Check size={18} color="#fff" strokeWidth={3} />
        ) : (
          <Icon size={18} color="#108A82" />
        )}
      </View>
      <View className="flex-1">
        <View className="flex-row items-baseline gap-1.5">
          <Text className="font-bold text-[11px] text-ink-50">
            {String(sec.nr).padStart(2, '0')}
          </Text>
          <Text className="font-bold text-[15px] text-ink">{sec.title}</Text>
        </View>
        <Text className="mt-0.5 text-[12px] leading-[16px] text-ink-50">{sec.sub}</Text>
      </View>
      <View
        className={`rounded-pill px-2.5 py-1 ${
          active ? 'bg-mint-500' : done ? 'bg-transparent' : 'bg-transparent'
        }`}
      >
        <Text
          className={`font-bold text-[11px] ${
            active ? 'text-white' : done ? 'text-mint-700' : 'text-ink-50'
          }`}
        >
          {active ? 'Verder' : done ? '✓ Klaar' : `~${sec.minutes} min`}
        </Text>
      </View>
    </Pressable>
  );
}

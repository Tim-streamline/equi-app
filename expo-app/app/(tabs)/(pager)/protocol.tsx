import { ReactNode, useEffect, useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable, Modal } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  Activity,
  AlertTriangle,
  BarChart3,
  BookOpen,
  Check,
  X,
  ChevronLeft,
  ChevronRight,
  Leaf,
  Footprints,
  PawPrint,
  ShoppingBag,
  Users,
  Clock3,
} from 'lucide-react-native';
import { IconButton } from '@/components/ui/IconButton';
import { Button } from '@/components/ui/Button';
import { Avatar } from '@/components/ui/Avatar';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { IntakeEntryCard } from '@/components/intake/IntakeEntryCard';
import { useIntake } from '@/lib/intake/store';
import {
  useActiveProtocolForHorse,
  useAllTaskCompletions,
  usePhaseItems,
  useProtocolAnalysis,
  useProtocolPhases,
  useProtocolTasks,
  useStoreMutations,
  useCurrentHorseId,
  useCurrentUser,
  useHorse,
  useTodayTasks,
} from '@/db/hooks';

type Tab = 'vandaag' | 'kalender' | 'voeding' | 'management' | 'analyse';

const ADVICE_ICONS: Record<string, (props: { size: number; color: string }) => any> = {
  leaf: (p) => <Leaf {...p} />,
  run: (p) => <Footprints {...p} />,
  horse: (p) => <PawPrint {...p} />,
};

function isTab(v: unknown): v is Tab {
  return v === 'vandaag' || v === 'kalender' || v === 'voeding' || v === 'management' || v === 'analyse';
}

const TABS: { key: Tab; label: string }[] = [
  { key: 'vandaag', label: 'Vandaag' },
  { key: 'kalender', label: 'Kalender' },
  { key: 'voeding', label: 'Voeding' },
  { key: 'management', label: 'Management' },
  { key: 'analyse', label: 'Analyse' },
];

const WEEKDAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
const MONTHS_LOWER = [
  'januari', 'februari', 'maart', 'april', 'mei', 'juni',
  'juli', 'augustus', 'september', 'oktober', 'november', 'december',
];

function todayLabel(date: Date) {
  return `${WEEKDAYS[date.getDay()]} ${date.getDate()} ${MONTHS_LOWER[date.getMonth()]}`;
}

export default function ProtocolListScreen() {
  // `tab` selects the initial sub-tab (e.g. opened as Kalender from Home); `t`
  // is a nonce so repeat navigations with the same tab still re-apply it.
  const { tab: tabParam, t: tabNonce } = useLocalSearchParams<{ tab?: string; t?: string }>();
  const [tab, setTab] = useState<Tab>(isTab(tabParam) ? tabParam : 'vandaag');
  useEffect(() => {
    if (isTab(tabParam)) setTab(tabParam);
  }, [tabParam, tabNonce]);
  const padBottom = useTabBarPadding();
  const protocol = useActiveProtocolForHorse();
  const horse = useHorse();
  const user = useCurrentUser();
  const { state: intake } = useIntake();
  const [ordersOpen, setOrdersOpen] = useState(false);

  if (!protocol) {
    // No therapist-built protocol yet. Surface the intake entry as the empty
    // state — once submitted it disappears and we show the "waiting on
    // Shelley" placeholder until the protocol arrives.
    const submitted = !!intake.submittedAt;
    return (
      <View className="flex-1">
        <SafeAreaView edges={['top']} style={{ flex: 1 }}>
          <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
            <View className="px-4 pt-2">
              {!submitted ? (
                <IntakeEntryCard variant="standalone" />
              ) : (
                <View className="rounded-card border border-ink-8 bg-white p-5">
                  <Text className="font-bold text-ink text-[16px]">Intake verzonden</Text>
                  <Text className="mt-1 text-[13px] leading-[18px] text-ink-70">
                    Shelley bouwt nu jouw protocol — je krijgt een notificatie zodra het klaar
                    staat (binnen 3 werkdagen).
                  </Text>
                </View>
              )}
            </View>
          </ScrollView>
        </SafeAreaView>
      </View>
    );
  }

  const now = new Date();
  const sub = tab === 'vandaag'
    ? `Week ${protocol.currentWeek ?? 1} van ${protocol.totalWeeks ?? 1} · ${todayLabel(now)}`
    : tab === 'kalender'
      ? `Kalender · ${MONTHS_NL[now.getMonth()].toLowerCase()} ${now.getFullYear()}`
      : tab === 'voeding'
        ? 'Basisvoeding'
        : tab === 'management'
          ? 'Managementadvies'
          : 'Analyse en fases';

  return (
    <View className="flex-1">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <View className="bg-canvas px-5 pb-3 pt-1">
          <View className="flex-row items-start justify-between gap-3">
            <View className="flex-1 pt-0.5">
              <Text className="font-bold text-ink" style={{ fontSize: 26, lineHeight: 29 }}>
                {(horse.name as string) || (protocol.title as string)}
              </Text>
              <Text className="mt-0.5 text-[13px] text-ink-50">{sub}</Text>
            </View>
            <View className="flex-row items-center gap-2">
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Open bestellijst"
                onPress={() => setOrdersOpen(true)}
                className="h-[38px] w-[38px] items-center justify-center rounded-xl border border-ink-15 bg-white active:border-mint-500"
              >
                <ShoppingBag size={19} color="#127A79" strokeWidth={2} />
              </Pressable>
              <Avatar initial={((user.name as string) || 'K').charAt(0).toUpperCase()} size={38} />
            </View>
          </View>
        </View>

        <View className="h-[46px] flex-row items-stretch justify-between border-b border-ink-8 bg-canvas px-4">
          {TABS.map(({ key, label }) => {
            const active = tab === key;
            return (
              <Pressable
                key={key}
                accessibilityRole="tab"
                accessibilityState={{ selected: active }}
                onPress={() => setTab(key)}
                className="justify-center border-b-2 pb-[11px] pt-1"
                style={{ borderBottomColor: active ? '#18BAB0' : 'transparent' }}
              >
                <Text
                  className={`font-semi text-[13.5px] ${active ? 'text-mint-700' : 'text-ink-70'}`}
                  style={{ lineHeight: 20, paddingBottom: 1 }}
                >
                  {label}
                </Text>
              </Pressable>
            );
          })}
        </View>

        <ScrollView
          showsVerticalScrollIndicator={false}
          contentContainerStyle={{ paddingTop: 16, paddingBottom: padBottom }}
        >
          {tab === 'vandaag' && <TodayView protocol={protocol} />}
          {tab === 'kalender' && <ProtocolCalendar protocolId={protocol.id} />}
          {tab === 'voeding' && <NutritionView weightKg={(horse.weightKg as number) || 500} />}
          {tab === 'management' && <ManagementView />}
          <View style={{ display: tab === 'analyse' ? 'flex' : 'none' }}>
            <ProtocolAnalyseView protocolId={protocol.id} />
            <View className="mt-4">
              <ProtocolPhasesView protocolId={protocol.id} />
            </View>
          </View>
        </ScrollView>
        <OrderSheet visible={ordersOpen} protocolId={protocol.id} onClose={() => setOrdersOpen(false)} />
      </SafeAreaView>
    </View>
  );
}

function TodayView({ protocol }: { protocol: any }) {
  const horseId = useCurrentHorseId();
  const mutations = useStoreMutations();
  const now = new Date();
  const iso = toIso(now.getFullYear(), now.getMonth(), now.getDate());
  const allTasks = useTodayTasks(protocol.id, iso);
  const tasks = allTasks.filter((task: any) => {
    const starts = !task.activeFrom || String(task.activeFrom).slice(0, 10) <= iso;
    const ends = !task.activeUntil || String(task.activeUntil).slice(0, 10) >= iso;
    return starts && ends;
  });
  const feeding = tasks.filter((task: any) => task.kind === 'feeding');
  const loose = tasks.filter((task: any) => task.kind !== 'feeding');
  const upcoming = allTasks.filter((task: any) => task.activeFrom && String(task.activeFrom).slice(0, 10) > iso);
  const done = tasks.filter((task: any) => task.done).length;
  const percentage = tasks.length ? Math.round((done / tasks.length) * 100) : 0;

  return (
    <View className="px-5">
      <View className="mb-4 rounded-[20px] border border-ink-8 bg-white px-[18px] py-4 shadow-sm">
        <View className="flex-row items-center justify-between gap-3">
          <View>
            <Text className="font-bold text-[16px] text-ink">Vandaag afvinken</Text>
            <Text className="mt-0.5 text-[13px] text-ink-50">{done} van {tasks.length} gedaan</Text>
          </View>
          <Text className="font-bold text-[15px] text-mint-700">{percentage}%</Text>
        </View>
        <View className="mt-3 h-1.5 overflow-hidden rounded-pill bg-ink-8">
          <View className="h-full rounded-pill bg-mint-500" style={{ width: `${percentage}%` }} />
        </View>
      </View>

      <TaskSection
        title={feeding.length ? 'Door de geweekte bijvoeding' : 'Protocol voor vandaag'}
        tasks={feeding.length ? feeding : tasks}
        onToggle={(id) => mutations.toggleTaskCompletion(id, iso, horseId)}
      />

      {upcoming.length > 0 && (
        <View className="mb-5 rounded-[18px] border border-warning/40 bg-[#FDF4E4] px-[17px] py-[15px]">
          <View className="flex-row items-start gap-2.5">
            <AlertTriangle size={18} color="#B5842B" strokeWidth={2} style={{ marginTop: 1 }} />
            <View className="flex-1">
              <Text className="font-bold text-[14.5px] text-[#7A5A16]">Binnenkort starten</Text>
              <Text className="mt-0.5 text-[13px] leading-[19px] text-ink-70">
                Check of je de volgende onderdelen al in huis hebt.
              </Text>
            </View>
          </View>
          <View className="mt-3 gap-1.5">
            {upcoming.map((task: any) => (
              <View key={task.id} className="flex-row items-center justify-between rounded-xl bg-white/60 px-3 py-2">
                <Text className="flex-1 font-semi text-[13.5px] text-[#5B4413]">{task.label}</Text>
                {!!task.meta && <Text className="ml-2 font-bold text-[13px] text-[#7A5A16]">{task.meta}</Text>}
              </View>
            ))}
          </View>
        </View>
      )}

      {feeding.length > 0 && loose.length > 0 && (
        <TaskSection
          title="Los geven"
          tasks={loose}
          onToggle={(id) => mutations.toggleTaskCompletion(id, iso, horseId)}
        />
      )}

      {tasks.length === 0 && (
        <View className="rounded-[18px] border border-ink-8 bg-white p-5">
          <Text className="font-bold text-[15px] text-ink">Vandaag staat er niets gepland</Text>
          <Text className="mt-1 text-[13px] leading-[19px] text-ink-50">
            Je protocol is helemaal bijgewerkt. Bekijk de kalender voor de volgende stap.
          </Text>
        </View>
      )}
    </View>
  );
}

function TaskSection({ title, tasks, onToggle }: { title: string; tasks: any[]; onToggle: (id: string) => void }) {
  if (tasks.length === 0) return null;
  return (
    <View className="mb-5">
      <Text className="mb-2.5 font-semi uppercase text-ink-50" style={{ fontSize: 11, letterSpacing: 1.3 }}>
        {title}
      </Text>
      <View className="gap-1.5">
        {tasks.map((task) => (
          <Pressable
            key={task.id}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: !!task.done }}
            onPress={() => onToggle(task.id)}
            className="flex-row items-center gap-2.5 rounded-[13px] border border-ink-8 bg-white px-3 py-2.5 active:bg-mint-50"
          >
            <View className={`h-[21px] w-[21px] items-center justify-center rounded-[7px] border-2 ${task.done ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-transparent'}`}>
              {task.done && <Check size={12} color="#fff" strokeWidth={3.5} />}
            </View>
            <Text numberOfLines={1} className={`flex-1 font-semi text-[14px] ${task.done ? 'text-ink-30 line-through' : 'text-ink'}`}>
              {task.label}
            </Text>
            {!!task.meta && (
              <Text numberOfLines={1} className="max-w-[38%] font-bold text-[13px] text-teal-500">
                {task.meta}
              </Text>
            )}
          </Pressable>
        ))}
      </View>
    </View>
  );
}

function NutritionView({ weightKg }: { weightKg: number }) {
  const hayMin = Math.round(weightKg * 0.02 * 10) / 10;
  const hayMax = Math.round(weightKg * 0.03 * 10) / 10;

  return (
    <View className="px-5">
      <View className="mb-3 rounded-[20px] bg-teal-700 px-5 py-[18px]">
        <Text className="mb-2 font-semi uppercase text-mint-200" style={{ fontSize: 10.5, letterSpacing: 1.3 }}>
          Ruwvoer, de basis
        </Text>
        <View className="flex-row items-baseline gap-2">
          <Text className="font-bold text-[27px] leading-[29px] text-canvas">{hayMin} – {hayMax} kg</Text>
          <Text className="font-medium text-[12px] text-white/60">per 24 uur</Text>
        </View>
        <Text className="mt-2 text-[12px] leading-[18px] text-white/70">
          2 tot 3 kg ruwvoer per 100 kg gewenst lichaamsgewicht. Bij een streefgewicht van {weightKg} kg komt dat uit op {hayMin} tot {hayMax} kg per dag. Maximaal 1 uur leegstand, ook &apos;s nachts, liever geen.
        </Text>
        <View className="mt-3.5 flex-row gap-2 border-t border-white/15 pt-3.5">
          <NutritionMetric label="Suiker" value="onder 7%" />
          <NutritionMetric label="Eiwit" value="6 – 9%" />
        </View>
      </View>

      <Pressable
        onPress={() => router.push('/(tabs)/(pager)/library')}
        className="mb-4 flex-row items-center gap-3 rounded-2xl border border-ink-8 bg-white px-3.5 py-3 active:bg-mint-50"
      >
        <View className="h-[34px] w-[34px] items-center justify-center rounded-[10px] bg-mint-50">
          <BookOpen size={18} color="#108A82" strokeWidth={2} />
        </View>
        <View className="flex-1">
          <Text className="font-semi text-[13.5px] text-ink">Hooi laten analyseren of zelf testen</Text>
          <Text className="mt-0.5 text-[12px] text-ink-50">Check uitleg in de bibliotheek</Text>
        </View>
        <ChevronRight size={16} color="rgba(27,42,42,0.35)" strokeWidth={2.2} />
      </Pressable>

      <View className="mb-4 rounded-[20px] border border-ink-8 bg-white p-[18px]">
        <Text className="mb-3 font-bold text-[15.5px] text-ink">Bijvoeding: goedgekeurd</Text>
        <View className="gap-2.5">
          <AdviceLine title="Metazoa balancer · 250 g per dag" body="Prima basis met anorganische mineralen. Blijft ongewijzigd." />
          <AdviceLine title="Digestfit Esparcette of Okapi Heucobs" body="Geweekt tot slobber, als drager voor het protocol. Zo min mogelijk, max 250 g." />
        </View>
      </View>

      <View className="rounded-[20px] border border-ink-8 bg-white p-[18px]">
        <Text className="font-bold text-[15.5px] text-ink">Water</Text>
        <Text className="mt-1 text-[13.5px] leading-[21px] text-ink-70">
          Drinkbakken wekelijks schoonmaken, ook de kuip buiten. Goed drinkwater is de voorwaarde voor alles wat het protocol doet.
        </Text>
        <View className="mt-3 self-start flex-row items-center gap-2 rounded-pill bg-mint-50 px-3 py-2">
          <Clock3 size={13} color="#0E6F69" strokeWidth={2.2} />
          <Text className="font-semi text-[12px] text-mint-800">Herinnering elke zondag</Text>
        </View>
      </View>
    </View>
  );
}

function NutritionMetric({ label, value }: { label: string; value: string }) {
  return (
    <View className="flex-1 rounded-[10px] bg-white/10 px-2.5 py-2">
      <Text className="uppercase text-[9.5px] text-white/60" style={{ letterSpacing: 0.7 }}>{label}</Text>
      <Text className="mt-0.5 font-bold text-[14px] text-white">{value}</Text>
    </View>
  );
}

function AdviceLine({ title, body }: { title: string; body: string }) {
  return (
    <View className="flex-row items-start gap-2.5">
      <Check size={17} color="#18BAB0" strokeWidth={2.6} style={{ marginTop: 2 }} />
      <View className="flex-1">
        <Text className="font-semi text-[14px] text-ink">{title}</Text>
        <Text className="text-[12.5px] leading-[18px] text-ink-50">{body}</Text>
      </View>
    </View>
  );
}

function ManagementView() {
  return (
    <View className="gap-3 px-5">
      <ManagementCard
        icon={<Activity size={20} color="#108A82" strokeWidth={2} />}
        title="Beweging & weidegang"
        intro="Dagelijks rustig bewegen binnen de grenzen van het revalidatieplan. Voor het gewicht, de benen, de darmen én de luchtwegen."
        items={[
          ['15 tot 20 minuten stap aan de hand is al waardevol', false],
          ['Korte stukken tellen mee, het gaat om regelmaat', false],
          ['Snackwandeling met vers groen: wilg, weegbree, brandnetel', false],
          ['Van het gras afhouden tijdens het protocol', true],
        ]}
      />
      <ManagementCard
        icon={<BarChart3 size={20} color="#108A82" strokeWidth={2} />}
        title="Training & belasting"
        items={[
          ['Maximaal 3 × 2 minuten draf, zoals in het revalidatieplan', false],
          ['Geen korte volte aan de longeerlijn', true],
          ['Laat gedrag en herstel het tempo bepalen, niet de kalender', false],
        ]}
        note="Hoest bij het aandraven? Noteer het en stuur het door, dit kan met spanning rond het middenrif samenhangen."
      />
      <ManagementCard
        icon={<Users size={20} color="#108A82" strokeWidth={2} />}
        title="Stress & kudde"
        items={[
          ['Voer in porties gescheiden aanbieden bij voernijd', false],
          ['Grote veranderingen zoveel mogelijk vermijden of gefaseerd', false],
          ['Bachbloesems: Chicory, Vine, Holly en Walnut, 8 weken', false],
        ]}
      />
    </View>
  );
}

function ManagementCard({
  icon,
  title,
  intro,
  items,
  note,
}: {
  icon: ReactNode;
  title: string;
  intro?: string;
  items: [string, boolean][];
  note?: string;
}) {
  return (
    <View className="rounded-[20px] border border-ink-8 bg-white p-[18px]">
      <View className="mb-3 flex-row items-center gap-3">
        <View className="h-[38px] w-[38px] items-center justify-center rounded-xl bg-mint-50">{icon}</View>
        <Text className="flex-1 font-bold text-[16px] text-ink">{title}</Text>
      </View>
      {!!intro && <Text className="mb-3.5 text-[13.5px] leading-[21px] text-ink-70">{intro}</Text>}
      <View className="gap-2.5">
        {items.map(([text, negative]) => (
          <View key={text} className="flex-row items-start gap-2.5">
            {negative ? (
              <X size={16} color="#C2543E" strokeWidth={2.6} style={{ marginTop: 2 }} />
            ) : (
              <Check size={16} color="#18BAB0" strokeWidth={2.6} style={{ marginTop: 2 }} />
            )}
            <Text className="flex-1 text-[14px] leading-[20px] text-ink">{text}</Text>
          </View>
        ))}
      </View>
      {!!note && (
        <View className="mt-3.5 rounded-[14px] bg-[#FDF4E4] px-3.5 py-3">
          <Text className="text-[13px] leading-[19px] text-[#7A5A16]">{note}</Text>
        </View>
      )}
    </View>
  );
}

function OrderSheet({ visible, protocolId, onClose }: { visible: boolean; protocolId: string; onClose: () => void }) {
  const tasks = useProtocolTasks(protocolId);
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable onPress={onClose} className="flex-1 justify-end bg-teal-800/45">
        <Pressable onPress={(event) => event.stopPropagation()} className="max-h-[82%] rounded-t-[28px] bg-canvas px-5 pb-2 pt-3">
          <View className="mb-4 h-1 w-10 self-center rounded-pill bg-ink-15" />
          <View className="mb-4 flex-row items-start justify-between gap-3">
            <View className="flex-1">
              <Text className="font-bold text-[22px] leading-[25px] text-ink">Bestellijst</Text>
              <Text className="mt-1 text-[13px] leading-[19px] text-ink-50">Alles wat je voor het actieve protocol nodig hebt.</Text>
            </View>
            <IconButton onPress={onClose}><X size={20} color="#1B2A2A" /></IconButton>
          </View>
          <ScrollView showsVerticalScrollIndicator={false} className="mb-3 rounded-[18px] border border-ink-8 bg-white">
            {tasks.map((task: any, index: number) => (
              <View key={task.id} className={`flex-row items-center justify-between gap-3 px-4 py-3 ${index < tasks.length - 1 ? 'border-b border-ink-8' : ''}`}>
                <Text className="flex-1 font-semi text-[14.5px] text-ink">{task.label}</Text>
                {!!task.meta && <Text className="font-bold text-[13px] text-teal-500">{task.meta}</Text>}
              </View>
            ))}
          </ScrollView>
          <SafeAreaView edges={['bottom']}>
            <Button title="Sluiten" onPress={onClose} />
          </SafeAreaView>
        </Pressable>
      </Pressable>
    </Modal>
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

function toIso(year: number, month0: number, day: number) {
  return `${year}-${String(month0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

type CalendarDayState = 'complete' | 'partial' | 'missed' | 'default';

const CALENDAR_DAY_TONES: Record<CalendarDayState, { bg: string; border: string; text: string }> = {
  complete: { bg: '#18BAB0', border: '#18BAB0', text: '#FFFFFF' },
  partial: { bg: '#99E8DF', border: '#99E8DF', text: '#0A4F4B' },
  missed: { bg: '#F7E2C4', border: '#E8A33C', text: '#8A6417' },
  default: { bg: '#FFFFFF', border: 'rgba(27,42,42,0.10)', text: 'rgba(27,42,42,0.38)' },
};

function ProtocolCalendar({ protocolId }: { protocolId: string }) {
  const tasks = useProtocolTasks(protocolId);
  const allCompletions = useAllTaskCompletions();

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

  const getDayState = (iso: string): CalendarDayState => {
    const activeTasks = tasks.filter((task: any) => {
      const starts = !task.activeFrom || String(task.activeFrom).slice(0, 10) <= iso;
      const ends = !task.activeUntil || String(task.activeUntil).slice(0, 10) >= iso;
      return starts && ends;
    });
    if (activeTasks.length === 0 || iso > todayIso) return 'default';

    const dayCompletions = completionsByDate[iso] ?? {};
    const doneCount = activeTasks.filter((task: any) => dayCompletions[task.id]).length;
    if (doneCount === activeTasks.length) return 'complete';
    if (doneCount > 0) return 'partial';
    return iso < todayIso ? 'missed' : 'default';
  };

  return (
    <View>
      <View className="mx-5 rounded-[22px] border border-ink-8 bg-white px-4 pb-4 pt-[18px]">
        <View className="mb-4 flex-row items-center justify-between">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Vorige maand"
            onPress={() => changeMonth(-1)}
            className="h-8 w-8 items-center justify-center rounded-full active:bg-ink-8"
            hitSlop={8}
          >
            <ChevronLeft size={17} color="rgba(27,42,42,0.42)" strokeWidth={2.2} />
          </Pressable>
          <Text className="font-bold text-[15px] text-ink">{`${MONTHS_NL[viewMonth0]} ${viewYear}`}</Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Volgende maand"
            onPress={() => changeMonth(1)}
            className="h-8 w-8 items-center justify-center rounded-full active:bg-ink-8"
            hitSlop={8}
          >
            <ChevronRight size={17} color="rgba(27,42,42,0.42)" strokeWidth={2.2} />
          </Pressable>
        </View>
        <View className="mb-1.5 flex-row">
          {DOW.map((d) => (
            <Text
              key={d}
              className="flex-1 text-center font-semi uppercase text-ink-30"
              style={{ fontSize: 9.5, letterSpacing: 0.75 }}
            >
              {d}
            </Text>
          ))}
        </View>
        <View className="-mx-[2.5px] flex-row flex-wrap">
          {cells.map((day, ci) => {
            if (day == null) {
              return <View key={`b-${ci}`} style={{ width: `${100 / 7}%`, aspectRatio: 1 }} />;
            }
            const iso = toIso(viewYear, viewMonth0, day);
            const isToday = iso === todayIso;
            const isSelected = iso === selected;
            const tone = isToday
              ? { bg: '#0B4A49', border: '#0B4A49', text: '#FFFFFF' }
              : CALENDAR_DAY_TONES[getDayState(iso)];
            return (
              <View key={iso} style={{ width: `${100 / 7}%`, aspectRatio: 1, padding: 2.5 }}>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`${day} ${MONTHS_NL[viewMonth0]} ${viewYear}`}
                  accessibilityState={{ selected: isSelected }}
                  onPress={() => setSelected(iso)}
                  className="flex-1 items-center justify-center rounded-xl"
                  style={{
                    backgroundColor: tone.bg,
                    borderWidth: 1,
                    borderColor: isSelected && !isToday ? '#0B4A49' : tone.border,
                  }}
                >
                  <Text className="font-semi text-[13px]" style={{ color: tone.text }}>
                    {day}
                  </Text>
                </Pressable>
              </View>
            );
          })}
        </View>
        <View className="mt-3.5 flex-row gap-4 px-1">
          <CalendarLegend color="#18BAB0" label="Compleet" />
          <CalendarLegend color="#99E8DF" label="Deels" />
          <CalendarLegend color="#E8A33C" label="Gemist" />
        </View>
      </View>
      <CalendarTimeline protocolId={protocolId} />
    </View>
  );
}

function CalendarLegend({ color, label }: { color: string; label: string }) {
  return (
    <View className="flex-row items-center gap-1.5">
      <View className="h-2.5 w-2.5 rounded-[4px]" style={{ backgroundColor: color }} />
      <Text className="text-[11px] text-ink-50">{label}</Text>
    </View>
  );
}

function CalendarTimeline({ protocolId }: { protocolId: string }) {
  const phases = useProtocolPhases(protocolId);
  return (
    <View className="mx-5 mt-4">
      <Text className="mb-2.5 font-semi uppercase text-ink-50" style={{ fontSize: 11, letterSpacing: 1.32 }}>
        Verloop van het protocol
      </Text>
      <View className="gap-2">
        {phases.map((phase: any) => (
          <CalendarTimelineRow key={phase.id} phase={phase} />
        ))}
      </View>
    </View>
  );
}

function CalendarTimelineRow({ phase }: { phase: any }) {
  const active = phase.state === 'active';
  const done = phase.state === 'done';
  const period = phase.weekStart > 0
    ? `Week ${phase.weekStart}${phase.weekEnd > phase.weekStart ? ` t/m ${phase.weekEnd}` : ''}`
    : 'Voorbereiding';
  return (
    <View className={`flex-row items-center rounded-2xl border bg-white px-[15px] py-[13px] ${active ? 'border-mint-500' : 'border-ink-8'}`}>
      <View className="flex-1 pr-3">
        <Text className="font-semi text-[14.5px] text-ink">{phase.title}</Text>
        <Text className="mt-0.5 text-[12px] text-ink-50">{period}</Text>
      </View>
      <View className={`rounded-pill px-2.5 py-1 ${active ? 'bg-mint-50' : done ? 'bg-mint-50' : 'bg-ink-8'}`}>
        <Text className={`font-semi text-[11px] ${active || done ? 'text-mint-700' : 'text-ink-50'}`}>
          {phase.chipLabel}
        </Text>
      </View>
      <ChevronRight size={15} color="rgba(27,42,42,0.30)" strokeWidth={2.2} style={{ marginLeft: 8 }} />
    </View>
  );
}

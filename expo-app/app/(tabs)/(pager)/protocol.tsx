import { useEffect, useMemo, useState } from 'react';
import { View, Text, ScrollView, Pressable, Modal } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  BookOpen,
  Check,
  X,
  ChevronLeft,
  ChevronRight,
  Leaf,
  Footprints,
  PawPrint,
  ShoppingBag,
} from 'lucide-react-native';
import { IconButton } from '@/components/ui/IconButton';
import { Button } from '@/components/ui/Button';
import { Avatar } from '@/components/ui/Avatar';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { IntakeEntryCard } from '@/components/intake/IntakeEntryCard';
import { useIntake } from '@/lib/intake/store';
import {
  useActiveProtocolForHorse,
  useAllSupplementIntakes,
  useProtocolAnalysis,
  useProtocolAdvice,
  useStoreMutations,
  useCurrentHorseId,
  useCurrentUser,
  useHorse,
  useProtocolPlan,
  useSupplementIntakesForDate,
} from '@/db/hooks';
import {
  formatProtocolWeeks,
  protocolOrderItems,
  protocolSupplementRowsForDay,
  supplementsForProtocolWeek,
  type ProtocolPlanPhase,
  type ProtocolPlanSupplement,
  type ProtocolSupplementDayRow,
} from '@/lib/protocol-plan';
import { adviceLayout, advicePresentationSections, type ProtocolAdviceItem } from '@/lib/protocol-advice';

type Tab = 'vandaag' | 'kalender' | 'voeding' | 'management' | 'beweging' | 'analyse';

const ADVICE_ICONS: Record<string, (props: { size: number; color: string }) => any> = {
  leaf: (p) => <Leaf {...p} />,
  run: (p) => <Footprints {...p} />,
  horse: (p) => <PawPrint {...p} />,
};

function isTab(v: unknown): v is Tab {
  return v === 'vandaag' || v === 'kalender' || v === 'voeding' || v === 'management' || v === 'beweging' || v === 'analyse';
}

const TABS: { key: Tab; label: string }[] = [
  { key: 'vandaag', label: 'Vandaag' },
  { key: 'kalender', label: 'Kalender' },
  { key: 'voeding', label: 'Voeding' },
  { key: 'management', label: 'Management' },
  { key: 'beweging', label: 'Beweging' },
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
  const protocolPlan = useProtocolPlan(protocol?.id ?? '');
  const protocolAdvice = useProtocolAdvice(protocol?.id ?? '');
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
          : tab === 'beweging'
            ? 'Bewegingsadvies'
            : 'Analyse en fases';

  const adviceFor = (category: 'voeding' | 'management' | 'beweging') =>
    protocolAdvice.find((item) => item.key === category)?.items ?? [];

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

        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          className="max-h-[46px] border-b border-ink-8 bg-canvas"
          contentContainerStyle={{ paddingHorizontal: 16, gap: 24 }}
        >
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
        </ScrollView>

        <ScrollView
          showsVerticalScrollIndicator={false}
          contentContainerStyle={{ paddingTop: 16, paddingBottom: padBottom }}
        >
          {tab === 'vandaag' && <TodayView protocol={protocol} plan={protocolPlan} />}
          {tab === 'kalender' && <ProtocolCalendar protocol={protocol} plan={protocolPlan} />}
          {tab === 'voeding' && <NutritionView advice={adviceFor('voeding')} plan={protocolPlan} />}
          {tab === 'management' && <ProtocolAdviceList category="management" items={adviceFor('management')} />}
          {tab === 'beweging' && <ProtocolAdviceList category="beweging" items={adviceFor('beweging')} />}
          <View style={{ display: tab === 'analyse' ? 'flex' : 'none' }}>
            <ProtocolAnalyseView protocolId={protocol.id} />
            <View className="mt-4">
              <ProtocolPhasesView plan={protocolPlan} />
            </View>
          </View>
        </ScrollView>
        <OrderSheet visible={ordersOpen} plan={protocolPlan} onClose={() => setOrdersOpen(false)} />
      </SafeAreaView>
    </View>
  );
}

function TodayView({ protocol, plan }: { protocol: any; plan: ProtocolPlanPhase[] }) {
  const horseId = useCurrentHorseId();
  const mutations = useStoreMutations();
  const now = new Date();
  const iso = toIso(now.getFullYear(), now.getMonth(), now.getDate());
  const supplementIntakes = useSupplementIntakesForDate(iso);
  const supplementRows = protocolSupplementRowsForDay(
    plan,
    Number(protocol.currentWeek ?? 0),
    supplementIntakes,
  );
  const totalItems = supplementRows.length;
  const done = supplementRows.filter((supplement) => supplement.done).length;
  const percentage = totalItems ? Math.round((done / totalItems) * 100) : 0;

  return (
    <View className="px-5">
      {totalItems > 0 && (
        <View className="mb-4 rounded-[20px] border border-ink-8 bg-white px-[18px] py-4 shadow-sm">
          <View className="flex-row items-center justify-between gap-3">
            <View>
              <Text className="font-bold text-[16px] text-ink">Vandaag afvinken</Text>
              <Text className="mt-0.5 text-[13px] text-ink-50">{done} van {totalItems} gedaan</Text>
            </View>
            <Text className="font-bold text-[15px] text-mint-700">{percentage}%</Text>
          </View>
          <View className="mt-3 h-1.5 overflow-hidden rounded-pill bg-ink-8">
            <View className="h-full rounded-pill bg-mint-500" style={{ width: `${percentage}%` }} />
          </View>
        </View>
      )}

      <ProtocolSupplementSection
        title="Door de geweekte bijvoeding"
        supplements={supplementRows}
        onToggle={(supplement) => mutations.toggleSupplementIntake(
          supplement.id,
          supplement.dosage,
          iso,
          horseId,
        )}
      />

      {supplementRows.length === 0 && (
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

function ProtocolSupplementSection({
  title,
  supplements,
  onToggle,
}: {
  title: string;
  supplements: ProtocolSupplementDayRow[];
  onToggle: (supplement: ProtocolSupplementDayRow) => void;
}) {
  if (supplements.length === 0) return null;

  return (
    <View className="mb-5">
      <Text className="mb-2.5 font-semi uppercase text-ink-50" style={{ fontSize: 11, letterSpacing: 1.3 }}>
        {title}
      </Text>
      <View className="gap-1.5">
        {supplements.map((supplement) => (
          <Pressable
            key={supplement.id}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: supplement.done }}
            accessibilityLabel={`${supplement.title}, ${supplement.dosage ?? 'dosering niet ingesteld'}`}
            onPress={() => onToggle(supplement)}
            className="flex-row items-center gap-2.5 rounded-[13px] border border-ink-8 bg-white px-3 py-2.5 active:bg-mint-50"
          >
            <View className={`h-[21px] w-[21px] items-center justify-center rounded-[7px] border-2 ${supplement.done ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-transparent'}`}>
              {supplement.done && <Check size={12} color="#fff" strokeWidth={3.5} />}
            </View>
            <Text numberOfLines={1} className={`flex-1 font-semi text-[14px] ${supplement.done ? 'text-ink-30 line-through' : 'text-ink'}`}>
              {supplement.title}
            </Text>
            <Text
              numberOfLines={1}
              className={`max-w-[38%] font-bold text-[13px] ${supplement.dosage ? 'text-teal-500' : 'text-warning'}`}
            >
              {supplement.dosage ?? 'Dosering niet ingesteld'}
            </Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

function SupplementPlanRow({
  supplement,
  showPhase = false,
}: {
  supplement: ProtocolPlanSupplement;
  showPhase?: boolean;
}) {
  const dosage = supplement.dosage ? String(supplement.dosage) : 'Dosering niet ingesteld';
  const frequency = Number(supplement.aantalPerWeek ?? 0);
  const details = [
    frequency > 0 ? `${frequency}× per week` : null,
    supplement.instructions ? String(supplement.instructions) : null,
  ].filter(Boolean).join(' · ');

  return (
    <View className="rounded-[16px] border border-ink-8 bg-white px-4 py-3">
      <View className="flex-row items-start justify-between gap-3">
        <View className="flex-1">
          <Text className="font-semi text-[14.5px] text-ink">{String(supplement.name ?? 'Supplement')}</Text>
          {showPhase && (
            <Text className="mt-0.5 text-[11.5px] text-ink-50">{supplement.phaseTitle}</Text>
          )}
        </View>
        <Text className={`font-bold text-[13px] ${supplement.dosage ? 'text-teal-500' : 'text-warning'}`}>
          {dosage}
        </Text>
      </View>
      {!!details && <Text className="mt-1.5 text-[12.5px] leading-[18px] text-ink-50">{details}</Text>}
    </View>
  );
}

function NutritionView({ advice, plan }: { advice: ProtocolAdviceItem[]; plan: ProtocolPlanPhase[] }) {
  const phasesWithSupplements = plan.filter((phase) => phase.supplements.length > 0);

  return (
    <View className="px-5">
      <ProtocolAdviceList category="voeding" items={advice} embedded />

      <View className="mt-4 rounded-[20px] border border-ink-8 bg-white p-[18px]">
        <Text className="font-bold text-[15.5px] text-ink">Supplementen in je protocol</Text>
        <Text className="mb-3 mt-1 text-[12.5px] leading-[18px] text-ink-50">
          De planning en dosering hieronder zijn door je therapeut voor dit paard ingesteld.
        </Text>
        {phasesWithSupplements.length > 0 ? (
          <View className="gap-4">
            {phasesWithSupplements.map((phase) => (
              <View key={phase.id}>
                <View className="mb-2 flex-row items-center justify-between gap-3">
                  <Text className="flex-1 font-semi text-[13.5px] text-ink">{String(phase.title ?? 'Fase')}</Text>
                  <Text className="text-[11.5px] text-ink-50">
                    {formatProtocolWeeks(phase.weeks.map((week) => Number(week.protocolWeekNumber)))}
                  </Text>
                </View>
                <View className="gap-2">
                  {phase.supplements.map((supplement) => (
                    <View key={supplement.id}>
                      <SupplementPlanRow supplement={supplement} />
                      <Text className="ml-1 mt-1 text-[11.5px] text-ink-50">
                        Ingepland: {formatProtocolWeeks(supplement.protocolWeekNumbers)}
                      </Text>
                    </View>
                  ))}
                </View>
              </View>
            ))}
          </View>
        ) : (
          <View className="rounded-[14px] bg-canvas-2 px-3.5 py-3">
            <Text className="text-[13px] leading-[19px] text-ink-50">
              Voor dit protocol zijn nog geen supplementen ingepland.
            </Text>
          </View>
        )}
      </View>

    </View>
  );
}

function ProtocolAdviceList({
  category,
  items,
  embedded = false,
}: {
  category: 'voeding' | 'management' | 'beweging';
  items: ProtocolAdviceItem[];
  embedded?: boolean;
}) {
  if (items.length === 0) {
    return (
      <View className={embedded ? '' : 'px-5'}>
        <View className="rounded-[18px] border border-ink-8 bg-white px-4 py-5">
          <Text className="font-semi text-[14px] text-ink">Nog geen advies ingesteld</Text>
          <Text className="mt-1 text-[12.5px] leading-[18px] text-ink-50">
            Je therapeut heeft voor deze categorie nog geen advies geselecteerd.
          </Text>
        </View>
      </View>
    );
  }

  const sections = advicePresentationSections(items);

  return (
    <View className={`${embedded ? '' : 'px-5'} gap-3`}>
      {sections.map((section) => section.layout === 'supplementary_feed' ? (
        <SupplementaryFeedAdvice key={section.layout} items={section.items} />
      ) : section.items.map((item) => (
        <ProtocolAdviceCard key={item.id} item={item} category={category} />
      )))}
    </View>
  );
}

function SupplementaryFeedAdvice({ items }: { items: ProtocolAdviceItem[] }) {
  return (
    <View className="rounded-[20px] border border-ink-8 bg-white p-[18px]">
      <Text className="mb-3 font-bold text-[15.5px] text-ink">Bijvoeding: goedgekeurd</Text>
      <View className="gap-3">
        {items.map((item) => (
          <View key={item.id} className="flex-row items-start gap-2.5">
            <Check size={17} color="#18BAB0" strokeWidth={2.6} style={{ marginTop: 2 }} />
            <View className="flex-1">
              <Text className="font-semi text-[14px] leading-[20px] text-ink">{String(item.title ?? 'Bijvoeding')}</Text>
              {!!item.description && (
                <Text className="mt-0.5 text-[12.5px] leading-[18px] text-ink-50">{String(item.description)}</Text>
              )}
            </View>
          </View>
        ))}
      </View>
    </View>
  );
}

function ProtocolAdviceCard({ item, category }: { item: ProtocolAdviceItem; category: 'voeding' | 'management' | 'beweging' }) {
  const layout = adviceLayout(item.layout);
  const title = String(item.title ?? 'Advies');
  const description = String(item.description ?? '');
  const CategoryIcon = category === 'voeding' ? Leaf : category === 'beweging' ? Footprints : PawPrint;

  if (layout === 'link_to_library') {
    return (
      <Pressable
        accessibilityRole="link"
        accessibilityLabel={`${title}, open bibliotheek`}
        onPress={() => router.push('/(tabs)/(pager)/library')}
        className="flex-row items-center gap-3 rounded-[18px] border border-ink-8 bg-white px-4 py-3.5 active:bg-mint-50"
      >
        <View className="size-10 items-center justify-center rounded-xl bg-mint-50">
          <BookOpen size={19} color="#108A82" strokeWidth={2} />
        </View>
        <View className="flex-1">
          <Text className="font-bold text-[14.5px] text-ink">{title}</Text>
          {!!description && <Text className="mt-0.5 text-[12.5px] leading-[18px] text-ink-50">{description}</Text>}
        </View>
        <ChevronRight size={17} color="rgba(27,42,42,0.35)" strokeWidth={2.2} />
      </Pressable>
    );
  }

  if (layout === 'roughage') {
    return (
      <View className="overflow-hidden rounded-[20px] bg-teal-700 px-5 py-[18px]">
        <View className="mb-3 flex-row items-center gap-2">
          <Leaf size={16} color="#99E8DF" strokeWidth={2.2} />
          <Text className="font-semi uppercase text-mint-200" style={{ fontSize: 10.5, letterSpacing: 1.3 }}>Ruwvoer, de basis</Text>
        </View>
        <Text className="font-bold text-[20px] leading-[24px] text-canvas">{title}</Text>
        {!!description && <Text className="mt-2 text-[13px] leading-[20px] text-white/75">{description}</Text>}
      </View>
    );
  }

  return (
    <View className="flex-row gap-3 rounded-[20px] border border-ink-8 bg-white p-[18px]">
      <View className="size-10 items-center justify-center rounded-xl bg-mint-50">
        <CategoryIcon size={19} color="#108A82" strokeWidth={2} />
      </View>
      <View className="flex-1">
        <Text className="font-bold text-[15px] text-ink">{title}</Text>
        {!!description && <Text className="mt-1 text-[13px] leading-[19px] text-ink-50">{description}</Text>}
      </View>
    </View>
  );
}

function OrderSheet({ visible, plan, onClose }: { visible: boolean; plan: ProtocolPlanPhase[]; onClose: () => void }) {
  const items = protocolOrderItems(plan);
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
            {items.map((item, index) => (
              <View key={item.id} className={`px-4 py-3 ${index < items.length - 1 ? 'border-b border-ink-8' : ''}`}>
                <View className="flex-row items-center justify-between gap-3">
                  <Text className="flex-1 font-semi text-[14.5px] text-ink">{item.name}</Text>
                  <Text className={`font-bold text-[13px] ${item.dosage ? 'text-teal-500' : 'text-warning'}`}>
                    {item.dosage ?? 'Geen dosering'}
                  </Text>
                </View>
                <Text className="mt-0.5 text-[11.5px] text-ink-50">{item.phaseTitles.join(' · ')}</Text>
              </View>
            ))}
            {items.length === 0 && (
              <Text className="px-4 py-5 text-center text-[13px] leading-[19px] text-ink-50">
                Er staan nog geen supplementen op de bestellijst.
              </Text>
            )}
          </ScrollView>
          <SafeAreaView edges={['bottom']}>
            <Button title="Sluiten" onPress={onClose} />
          </SafeAreaView>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

function ProtocolPhasesView({ plan }: { plan: ProtocolPlanPhase[] }) {
  return (
    <View className="px-4 gap-2.5">
      {plan.map((p) => (
        <PhaseCard key={p.id} phase={p} />
      ))}
    </View>
  );
}

function PhaseCard({ phase }: { phase: ProtocolPlanPhase }) {
  const tone =
    phase.state === 'done'
      ? { border: 'border-mint-200', chipBg: 'bg-mint-50', chipText: 'text-mint-700' }
      : phase.state === 'active'
        ? { border: 'border-mint-500', chipBg: 'bg-mint-500', chipText: 'text-white' }
        : { border: 'border-ink-8', chipBg: 'bg-ink-8', chipText: 'text-ink-70' };
  return (
    <View className={`rounded-2xl border-2 bg-white p-4 ${tone.border}`}>
      <View className="flex-row items-start justify-between">
        <Text className="flex-1 font-bold text-ink text-[15px] pr-3">{String(phase.title ?? 'Fase')}</Text>
        <View className={`flex-row items-center gap-1 rounded-pill px-2.5 py-1 ${tone.chipBg}`}>
          {phase.state === 'done' && <Check size={11} color="#108A82" strokeWidth={3} />}
          <Text className={`font-semi text-[11px] ${tone.chipText}`}>{String(phase.chipLabel ?? 'Komend')}</Text>
        </View>
      </View>
      {!!phase.description && (
        <Text className="mt-2 text-[13px] leading-[19px] text-ink-50">{String(phase.description)}</Text>
      )}
      <View className="mt-2 flex-row flex-wrap gap-1.5">
        <Text className="rounded-pill bg-canvas-2 px-2.5 py-1 text-[11px] text-ink-70">
          {formatProtocolWeeks(phase.weeks.map((week) => Number(week.protocolWeekNumber)))}
        </Text>
        {!!phase.required && (
          <Text className="rounded-pill bg-mint-50 px-2.5 py-1 font-semi text-[11px] text-mint-700">Vaste fase</Text>
        )}
        {Number(phase.startAfterPreviousPhaseWeeks ?? 0) > 0 && (
          <Text className="rounded-pill bg-[#FDF4E4] px-2.5 py-1 text-[11px] text-[#7A5A16]">
            Start na {Number(phase.startAfterPreviousPhaseWeeks)} weken van vorige fase
          </Text>
        )}
      </View>
      {phase.supplements.length > 0 && (
        <View className="mt-3 gap-2 border-t border-ink-8 pt-3">
          {phase.supplements.map((supplement) => (
            <View key={supplement.id}>
              <SupplementPlanRow supplement={supplement} />
              <Text className="ml-1 mt-1 text-[11.5px] text-ink-50">
                {formatProtocolWeeks(supplement.protocolWeekNumbers)}
              </Text>
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

function ProtocolCalendar({ protocol, plan }: { protocol: any; plan: ProtocolPlanPhase[] }) {
  const allIntakes = useAllSupplementIntakes();

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

  // Intake records keyed by full ISO date so the lookup works for any month.
  const intakesByDate = useMemo(() => {
    const map: Record<string, Record<string, boolean>> = {};
    allIntakes.forEach((intake) => {
      if (!intake.date) return;
      const date = String(intake.date).slice(0, 10);
      (map[date] ??= {})[intake.protocolPhaseSupplementId] = !!intake.done;
    });
    return map;
  }, [allIntakes]);

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
    const startedAt = protocol.startedAt ? String(protocol.startedAt).slice(0, 10) : null;
    const protocolWeek = startedAt
      ? Math.floor((new Date(`${iso}T00:00:00`).getTime() - new Date(`${startedAt}T00:00:00`).getTime()) / (7 * 86400000)) + 1
      : Number(protocol.currentWeek ?? 0);
    const activeSupplements = supplementsForProtocolWeek(plan, protocolWeek);
    if (activeSupplements.length === 0 || iso > todayIso) return 'default';

    const dayIntakes = intakesByDate[iso] ?? {};
    const doneCount = activeSupplements.filter((supplement) => dayIntakes[supplement.id]).length;
    if (doneCount === activeSupplements.length) return 'complete';
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
      <CalendarTimeline plan={plan} />
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

function CalendarTimeline({ plan }: { plan: ProtocolPlanPhase[] }) {
  return (
    <View className="mx-5 mt-4">
      <Text className="mb-2.5 font-semi uppercase text-ink-50" style={{ fontSize: 11, letterSpacing: 1.32 }}>
        Verloop van het protocol
      </Text>
      <View className="gap-2">
        {plan.map((phase) => (
          <CalendarTimelineRow key={phase.id} phase={phase} />
        ))}
      </View>
    </View>
  );
}

function CalendarTimelineRow({ phase }: { phase: ProtocolPlanPhase }) {
  const active = phase.state === 'active';
  const done = phase.state === 'done';
  const weekStart = Number(phase.weekStart ?? 0);
  const weekEnd = Number(phase.weekEnd ?? 0);
  const period = weekStart > 0
    ? `Week ${weekStart}${weekEnd > weekStart ? ` t/m ${weekEnd}` : ''}`
    : 'Voorbereiding';
  return (
    <View className={`flex-row items-center rounded-2xl border bg-white px-[15px] py-[13px] ${active ? 'border-mint-500' : 'border-ink-8'}`}>
      <View className="flex-1 pr-3">
        <Text className="font-semi text-[14.5px] text-ink">{String(phase.title ?? 'Fase')}</Text>
        <Text className="mt-0.5 text-[12px] text-ink-50">{period}</Text>
        {Number(phase.startAfterPreviousPhaseWeeks ?? 0) > 0 && (
          <Text className="mt-0.5 text-[11.5px] text-[#7A5A16]">
            Start na {Number(phase.startAfterPreviousPhaseWeeks)} weken van vorige fase
          </Text>
        )}
      </View>
      <View className={`rounded-pill px-2.5 py-1 ${active ? 'bg-mint-50' : done ? 'bg-mint-50' : 'bg-ink-8'}`}>
        <Text className={`font-semi text-[11px] ${active || done ? 'text-mint-700' : 'text-ink-50'}`}>
          {String(phase.chipLabel ?? 'Komend')}
        </Text>
      </View>
      <ChevronRight size={15} color="rgba(27,42,42,0.30)" strokeWidth={2.2} style={{ marginLeft: 8 }} />
    </View>
  );
}

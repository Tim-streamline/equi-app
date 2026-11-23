import { PurchasedProtocols } from '@/components/intake/PurchasedProtocols';
import { IntakeEntryCard } from "@/components/intake/IntakeEntryCard";
import { LibraryCard } from "@/components/library/LibraryCard";
import { useState } from "react";
import {
  View,
  Text,
  ScrollView,
  Pressable,
  ActivityIndicator,
  RefreshControl,
  Alert,
} from "react-native";
import { router } from "expo-router";
import { ArrowRight } from "lucide-react-native";
import { useTabBarPadding } from "@/hooks/useTabBarPadding";
import { useHorseDashboard } from "@/hooks/useHorseDashboard";
import { useCurrentUser, useHorse } from "@/db/hooks";
import { useDb } from "@/db/provider";
import { libraryPath, type HorseDashboard, type HorseProtocolSummary } from "@/lib/horse-dashboard";
import { SeasonalTipCard } from "@/components/home/SeasonalTipCard";
import { useHomePreferences } from "@/hooks/useHomePreferences";
import { seasonalTipVisible } from "@/lib/home-preferences";
import { BRAND_NAME } from "@/constants/brand";
import { OfflineStrip } from "@/components/ui/OfflineStrip";
import { ShelbyChatButton } from "@/components/shelby/ShelbyChatButton";
import { Button, PRIMARY_INK } from "@/components/ui/Button";
import { PLAIN_CARD, HOME_NOTICE_LABEL, HOME_NOTICE_BODY, HOME_NOTICE_LINK } from "@/components/ui/styles";
import { horsePossessive } from '@/lib/protocol-evaluation';

export default function HomeScreen() {
  const { data, error, refresh, loading } = useHorseDashboard();
  const horse = useHorse();
  const user = useCurrentUser();
  const hasHorse = !!horse.id;
  const hour = new Date().getHours();
  const greeting = `${hour < 12 ? 'Goedemorgen' : hour < 18 ? 'Goedemiddag' : 'Goedenavond'}${user.name ? `, ${String(user.name).trim().split(/\s+/)[0]}` : ''}`;
  const padBottom = useTabBarPadding();
  const [refreshing, setRefreshing] = useState(false);
  const seasonal = data?.seasonalTip;
  const { preferences, ready: preferencesReady, saving, save } = useHomePreferences();
  const openLibrary = () => router.push("/(tabs)/(pager)/library");
  const cards = protocolCards(data);
  const seasonalCard = hasHorse && preferencesReady && seasonal && seasonalTipVisible(seasonal, preferences) && (
    <SeasonalTipCard
      tip={seasonal}
      dismissing={saving}
      onOpen={() => seasonal.item ? router.push(libraryPath(seasonal.item) as any) : openLibrary()}
      onDismiss={() => void save({ type: "dismiss", tipId: seasonal.id }).catch(() =>
        Alert.alert("Seizoenstip niet verborgen", "Probeer het opnieuw."),
      )}
    />
  );
  return (
    <View className="flex-1 bg-canvas">
      {!!error && <OfflineStrip message={error} stale={!!data} onRetry={() => void refresh()} />}
      <ScrollView
        contentContainerStyle={{
          paddingHorizontal: 20,
          paddingBottom: padBottom + 72,
        }}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={async () => {
              setRefreshing(true);
              await refresh();
              setRefreshing(false);
            }}
          />
        }
      >
        <Text className="mb-5 mt-4 font-bold text-[25px] leading-[29px] text-ink">
          {data?.greeting ?? greeting}
        </Text>
        {/* Every horse's intake gets its own card; Home does not depend on a selected horse for this. */}
        {cards.filter(card => card.evaluation?.available).map(card => <EvaluationNotice key={`evaluation-${card.id}`} {...card} />)}
        <PurchasedProtocols allHorses excludeProtocolIds={cards.map(card => card.id)} />
        {cards.map((card) => <ProtocolCard key={card.horseId} {...card} />)}
        {!hasHorse && <View className="mb-5 rounded-2xl bg-white p-5">
          <Text className="mb-2 font-semi text-[20px] text-ink">Je paard toevoegen aan {BRAND_NAME}?</Text>
          <Text className="mb-4 text-[14px] text-ink-70">Voeg je paard toe aan je account, zodat je alle gegevens op één plek kunt bewaren.</Text>
          <Button title="Paard toevoegen" onPress={() => router.push('/onboarding/add-horse')} trailing={<ArrowRight size={18} color={PRIMARY_INK} />} />
        </View>}
        {loading && <ActivityIndicator color="#18BAB0" />}
        {data && (
          <>
            {seasonalCard}
            <View className="mb-3 mt-2 flex-row items-center justify-between gap-2">
              <Text accessibilityRole="header" className="flex-1 font-semi text-[16px] text-ink">
                Ontdek in de bibliotheek
              </Text>

            </View>
            <View className="flex-row flex-wrap justify-between gap-y-3">
              {data.recommendations.map((item) => (
                <LibraryCard key={item.id} item={item} compact access={{
                  hasPlus: data.hasPlus,
                  unlockedIds: data.recommendations.filter(recommendation => recommendation.unlocked).map(recommendation => recommendation.id),
                }} />
              ))}
            </View>
            <Pressable onPress={openLibrary} className="mb-5 mt-4 py-1">
              <Text className="font-semi text-[14px] text-teal-600">
                Bekijk de hele bibliotheek →
              </Text>
            </Pressable>
            {hasHorse && data.showPlusUpsell && (
              <Pressable
                accessibilityRole="link"
                onPress={() => router.push("/protocol-purchase")}
                className="rounded-[22px] bg-[#FFF0EC] p-5"
              >
                {/* Home has no horse selector, so say which horse the offer is for. */}
                {!!data.horse.name && <Text className="mb-1 font-semi text-[14px] text-ink-70">Voor {data.horse.name}</Text>}
                <Text className="font-semi text-[17px] leading-[23px] text-ink">
                  {data.homeUpsell?.title ?? 'Twijfel je wat jouw paard écht nodig heeft?'}
                </Text>
                <Text className="mt-2 text-[14px] leading-[22px] text-ink-70">
                  {data.homeUpsell?.body ?? 'Je hoeft het niet zelf uit te zoeken. Met EquiBoost krijg je een persoonlijk plan én 3 maanden begeleiding om gericht aan zijn gezondheid te werken.'}
                </Text>
                <Text className="mt-3 font-semi text-[14px] text-teal-600">
                  {data.homeUpsell?.button ?? 'Ontdek EquiBoost'}
                </Text>
              </Pressable>
            )}
          </>
        )}
      </ScrollView>
      <ShelbyChatButton bottom={padBottom}
        onPress={() => router.push({ pathname: "/nova-chat", params: { horseId: horse.id, protocolId: data?.protocol?.id ?? "" } })} />
    </View>
  );
}

/**
 * One card per horse with a protocol in progress, all from the dashboard's
 * summaries (one source, sorted by name by the server). Older cached responses
 * only have the selected horse's protocol.
 */
function protocolCards(data: HorseDashboard | null): HorseProtocolSummary[] {
  if (!data) return [];
  if (data.horseProtocols) return data.horseProtocols;
  return data.protocol ? [{ ...data.protocol, horseId: data.horse.id, horseName: data.horse.name }] : [];
}

/** A horse's protocol in progress; opens Protocol on that horse. */
function ProtocolCard({ id, horseId, horseName, statusLabel, currentDay, currentWeek, phaseLabel, progressPercent, preparing, startedAt }: HorseProtocolSummary) {
  const { selectHorse } = useDb();
  const readyToStart = preparing ?? (currentDay <= 0);
  const stopped = /gepauzeerd|afgerond|voltooid/i.test(statusLabel ?? '');
  // A start date that is still ahead: there is nothing to tick off yet.
  const upcoming = !readyToStart && !stopped && currentDay <= 0;
  const possessive = horseName ? horsePossessive(horseName) : 'Je';
  const plan = `${possessive} herstelplan`;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`Protocol van ${horseName || 'je paard'}`}
      onPress={() => {
        // Protocol shows the selected horse.
        selectHorse(horseId);
        router.push({
          pathname: "/(tabs)/(pager)/protocol",
          params: { tab: "vandaag", horseId, protocolId: id, orderList: readyToStart ? '1' : '0', t: String(Date.now()) },
        });
      }}
      className={`mb-4 ${PLAIN_CARD}`}
    >
      {!readyToStart && <Text className={HOME_NOTICE_LABEL}>
        {horseName ? `${possessive} EquiBoost traject` : "EquiBoost traject"}
      </Text>}
      <Text className={`${readyToStart ? '' : 'mt-3'} font-bold text-[20px] leading-[26px] text-ink`}>
        {readyToStart ? `Het herstelplan voor ${horseName || 'je paard'} staat klaar!` : stopped ? statusLabel
          : upcoming ? `${plan} start ${startedAt ? `op ${new Date(`${startedAt}T12:00:00`).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long' })}` : 'binnenkort'}`
          : `${plan} is in volle gang!`}
      </Text>
      <Text className={`${readyToStart ? 'mt-3' : 'mt-1'} ${HOME_NOTICE_BODY}`}>
        {readyToStart ? `Controleer welke kruiden, supplementen en andere producten je nodig hebt. Bestel wat je nog niet in huis hebt, zodat je op tijd met ${horseName ? `${possessive} herstelplan` : 'het herstelplan van je paard'} kunt starten.`
          : upcoming ? 'Vanaf die dag zie je hier wat je elke dag geeft.'
          : `Dag ${currentDay} · Week ${currentWeek}`}
        {!readyToStart && !upcoming && phaseLabel ? ` · ${phaseLabel}` : ""}
      </Text>
      {!readyToStart && !upcoming && <View className="mt-4 h-1.5 overflow-hidden rounded-full bg-ink-8">
        <View
          className="h-full bg-mint-500"
          style={{ width: `${progressPercent}%` }}
        />
      </View>}
      <Text className={`mt-4 ${HOME_NOTICE_LINK}`}>
        {readyToStart ? 'Bekijk hier je bestellijst →' : upcoming || /afgerond|voltooid/i.test(statusLabel ?? '') ? 'Bekijk je herstelplan →' : 'Vink af wat je vandaag hebt gegeven →'}
      </Text>
    </Pressable>
  );
}

/** Evaluation is an additional priority notice; daily tracking stays available below. */
function EvaluationNotice({ id, horseId, horseName, evaluation }: HorseProtocolSummary) {
  const { selectHorse } = useDb();
  if (!evaluation?.available) return null;
  const possessive = horseName ? horsePossessive(horseName) : 'Je';
  const remaining = evaluation.daysRemaining ?? 0;
  // A plan the therapist closed early has ended, whatever its scheduled last day.
  const ended = !!evaluation.completed || remaining < 0;
  const when = ended ? 'Inmiddels is' : remaining === 14 ? 'Over twee weken loopt' : remaining > 0 ? `Over ${remaining} ${remaining === 1 ? 'dag' : 'dagen'} loopt` : 'Vandaag loopt';
  const submitted = !!evaluation.submitted;
  return <Pressable accessibilityRole="button" accessibilityLabel={submitted ? `Bekijk of wijzig de evaluatie van ${horseName || 'je paard'}` : `Evalueer de gezondheid van ${horseName || 'je paard'}`}
    onPress={() => { selectHorse(horseId); router.push({ pathname: '/protocol-evaluation' as any, params: { protocolId: id } }); }}
    className={`mb-4 ${PLAIN_CARD}`}>
    <Text className={HOME_NOTICE_LABEL}>{possessive} EquiBoost traject</Text>
    <Text className="mt-3 font-bold text-[20px] leading-[26px] text-ink">{submitted ? 'Je evaluatie is verstuurd' : `${possessive} huidige EquiBoost traject is ${ended ? 'afgerond' : 'bijna afgerond'}!`}</Text>
    <Text className={`mt-3 ${HOME_NOTICE_BODY}`}>{submitted
      ? `Je therapeut kan je antwoorden over ${horseName || 'je paard'} bekijken. Je kunt ze nog aanvullen en opnieuw versturen.`
      : `${when} ${possessive} huidige herstelplan ${ended ? 'afgelopen' : 'af'}. Dit is hét moment om te evalueren hoe het nu met ${horseName || 'je paard'} gaat en welke vervolgstappen passend zijn.`}</Text>
    <Text className={`mt-4 ${HOME_NOTICE_LINK}`}>{submitted ? 'Bekijk of wijzig je evaluatie →' : `Evalueer ${possessive} gezondheid →`}</Text>
  </Pressable>;
}

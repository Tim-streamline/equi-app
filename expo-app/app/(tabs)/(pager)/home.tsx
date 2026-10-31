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
import { useCurrentUser, useHorse, useHorsesWithActiveProtocol } from "@/db/hooks";
import { useDb } from "@/db/provider";
import { libraryPath, type HorseDashboard } from "@/lib/horse-dashboard";
import { SeasonalTipCard } from "@/components/home/SeasonalTipCard";
import { useHomePreferences } from "@/hooks/useHomePreferences";
import { seasonalTipVisible } from "@/lib/home-preferences";
import { BRAND_NAME } from "@/constants/brand";
import { OfflineStrip } from "@/components/ui/OfflineStrip";
import { ShelbyChatButton } from "@/components/shelby/ShelbyChatButton";
import { Button, PRIMARY_INK } from "@/components/ui/Button";

export default function HomeScreen() {
  const { data, error, refresh, loading } = useHorseDashboard();
  const horse = useHorse();
  const user = useCurrentUser();
  const hasHorse = !!horse.id;
  const otherProtocolHorses = useHorsesWithActiveProtocol().filter((item) => item.id !== horse.id);
  const hour = new Date().getHours();
  const greeting = `${hour < 12 ? 'Goedemorgen' : hour < 18 ? 'Goedemiddag' : 'Goedenavond'}${user.name ? `, ${String(user.name).trim().split(/\s+/)[0]}` : ''}`;
  const padBottom = useTabBarPadding();
  const [refreshing, setRefreshing] = useState(false);
  const seasonal = data?.seasonalTip;
  const { preferences, ready: preferencesReady, saving, save } = useHomePreferences();
  const openLibrary = () => router.push("/(tabs)/(pager)/library");
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
        <PurchasedProtocols allHorses />
        {/* The selected horse's protocol comes with Home's dashboard; other horses get a light card from local data. */}
        {hasHorse && data?.protocol && <ProtocolCard data={data} />}
        {otherProtocolHorses.map((item) => <OtherProtocolCard key={item.id} horseId={item.id} name={String(item.name ?? '')} />)}
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

/** The selected horse's active protocol, with progress from Home's dashboard. */
function ProtocolCard({ data }: { data: HorseDashboard }) {
  const protocol = data.protocol!;
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() =>
        router.push({
          pathname: "/(tabs)/(pager)/protocol",
          params: { tab: "vandaag", t: String(Date.now()) },
        })
      }
      className="mb-4 rounded-[22px] bg-white p-5"
    >
      <Text className="text-[10px] uppercase tracking-[1.3px] text-ink-70">
        {data.horse.name}&apos;s protocol
      </Text>
      <Text className="mt-3 font-bold text-[18px] text-ink">
        {protocol.statusLabel}
      </Text>
      <Text className="mt-1 text-[13px] text-ink-70">
        Dag {protocol.currentDay} · Week {protocol.currentWeek}
        {protocol.phaseLabel ? ` · ${protocol.phaseLabel}` : ""}
      </Text>
      <View className="mt-4 h-1.5 overflow-hidden rounded-full bg-ink-8">
        <View
          className="h-full bg-mint-500"
          style={{ width: `${protocol.progressPercent}%` }}
        />
      </View>
      <Text className="mt-4 font-semi text-[14px] text-teal-600">
        Ga verder met het protocol →
      </Text>
    </Pressable>
  );
}

/** Another horse's active protocol (from local data; no extra dashboard request). */
function OtherProtocolCard({ horseId, name }: { horseId: string; name: string }) {
  const { selectHorse } = useDb();
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => {
        // Protocol shows the selected horse.
        selectHorse(horseId);
        router.push({
          pathname: "/(tabs)/(pager)/protocol",
          params: { tab: "vandaag", t: String(Date.now()) },
        });
      }}
      className="mb-4 rounded-[22px] bg-white p-5"
    >
      <Text className="text-[10px] uppercase tracking-[1.3px] text-ink-70">
        {name}&apos;s protocol
      </Text>
      <Text className="mt-3 font-bold text-[18px] text-ink">Actief protocol</Text>
      <Text className="mt-4 font-semi text-[14px] text-teal-600">
        Ga verder met het protocol →
      </Text>
    </Pressable>
  );
}

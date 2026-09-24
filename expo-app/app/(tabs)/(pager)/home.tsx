import { LibraryCard } from "@/components/library/LibraryCard";
import { useState } from "react";
import {
  View,
  Text,
  ScrollView,
  Pressable,
  Modal,
  ActivityIndicator,
  RefreshControl,
  Alert,
} from "react-native";
import { router } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import {
  ChevronDown,
  Sparkles,
  UserRound,
} from "lucide-react-native";
import { useTabBarPadding } from "@/hooks/useTabBarPadding";
import { useHorseDashboard } from "@/hooks/useHorseDashboard";
import { useHorse, useHorsesByOwner } from "@/db/hooks";
import { useDb } from "@/db/provider";
import { libraryPath, type HorseDashboard } from "@/lib/horse-dashboard";
import { SeasonalTipCard } from "@/components/home/SeasonalTipCard";
import { useHomePreferences } from "@/hooks/useHomePreferences";
import { seasonalTipVisible } from "@/lib/home-preferences";
import { ConnectionStatus } from "@/components/ui/ConnectionStatus";

export default function HomeScreen() {
  const { data, error, refresh, loading } = useHorseDashboard();
  const horse = useHorse();
  const horses = useHorsesByOwner().filter((item) => item.status === "active");
  const { selectHorse } = useDb();
  const [pickerOpen, setPickerOpen] = useState(false);
  const padBottom = useTabBarPadding();
  const [refreshing, setRefreshing] = useState(false);
  const seasonal = data?.seasonalTip;
  const { preferences, ready: preferencesReady, saving, save } = useHomePreferences();
  const openLibrary = () => router.push("/(tabs)/(pager)/library");
  const seasonalCard = preferencesReady && seasonal && seasonalTipVisible(seasonal, preferences) && (
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
    <SafeAreaView edges={["top"]} className="flex-1 bg-canvas">
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
        <View className="mb-5 mt-2 flex-row items-center justify-between">
          <Text className="font-semi text-[12px] tracking-[2px] text-mint-700">
            EQUI·APP
          </Text>
          <View className="flex-row items-center gap-4">
            <ConnectionStatus />
            <Pressable
              accessibilityRole="button"
              onPress={() => router.push("/(tabs)/account")}
              accessibilityLabel="Account openen"
            >
              <UserRound size={20} color="#466362" />
            </Pressable>
          </View>
        </View>
        <View className="mb-5 flex-row items-center justify-between gap-3">
          <Text className="flex-1 font-bold text-[25px] leading-[29px] text-ink">
            {data?.greeting ?? "Welkom"}
          </Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Wissel paard"
            onPress={() => setPickerOpen(true)}
            className="max-w-[45%] flex-row items-center gap-2 rounded-full bg-white px-3 py-2"
          >
            <View className="h-7 w-7 items-center justify-center rounded-full bg-mint-500">
              <Text className="font-semi text-[12px] text-white">
                {String(horse.name ?? "").charAt(0)}
              </Text>
            </View>
            <Text
              numberOfLines={1}
              className="shrink font-semi text-[13px] text-ink"
            >
              {horse.name ?? "Kies paard"}
            </Text>
            <ChevronDown size={14} color="#536C6B" />
          </Pressable>
        </View>
        {!!error && (
          <Pressable
            onPress={() => void refresh()}
            className="mb-4 rounded-xl bg-[#FFF3DF] p-3"
          >
            <Text className="text-[12px] text-[#7A5A16]">
              {data ? "Laatst opgehaalde gegevens. " : ""}
              {error} Tik om opnieuw te proberen.
            </Text>
          </Pressable>
        )}
        {loading && <ActivityIndicator color="#18BAB0" />}
        {data && (
          <>
            {data.protocol && <ProtocolCard data={data} />}
            {seasonalCard}
            <View className="mb-3 mt-2 flex-row items-center justify-between gap-2">
              <Text className="flex-1 font-semi text-[10px] uppercase tracking-[1.2px] text-ink-70">
                Ontdek in de bibliotheek
              </Text>
              {data.variant === "basic" && (
                <Text className="rounded-full bg-mint-50 px-2.5 py-1 font-semi text-[11px] text-mint-700">
                  {data.credits} credits
                </Text>
              )}
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
              <Text className="font-semi text-[14px] text-mint-700">
                Bekijk de hele bibliotheek →
              </Text>
            </Pressable>
            {data.showPlusUpsell && (
              <Pressable
                accessibilityRole="link"
                onPress={() => router.push("/plus")}
                className="rounded-[22px] bg-[#FFF0EC] p-5"
              >
                <Text className="font-semi text-[17px] leading-[23px] text-ink">
                  Wil je jouw paard écht gericht ondersteunen?
                </Text>
                <Text className="mt-2 text-[14px] leading-[22px] text-ink-70">
                  Ga voor Plus en ontvang een persoonlijk protocol op maat,
                  afgestemd op jouw paard, zijn klachten en jullie doelen.
                </Text>
                <Text className="mt-3 font-semi text-[14px] text-mint-700">
                  Ontdek Plus →
                </Text>
              </Pressable>
            )}
          </>
        )}
      </ScrollView>
      <Pressable accessibilityRole="button" accessibilityLabel="Vraag het Shelby"
        onPress={() => router.push({ pathname: "/nova-chat", params: { horseId: horse.id, protocolId: data?.protocol?.id ?? "" } })}
        style={{ position: 'absolute', right: 20, bottom: padBottom, width: 56, height: 56,
          alignItems: 'center', justifyContent: 'center', borderRadius: 28, backgroundColor: '#127A79',
          elevation: 4, shadowColor: '#105C5B', shadowOpacity: 0.18, shadowRadius: 8, shadowOffset: { width: 0, height: 3 } }}>
        <Sparkles size={24} color="#FFFFFF" />
      </Pressable>
      <Modal
        visible={pickerOpen}
        transparent
        animationType="slide"
        onRequestClose={() => setPickerOpen(false)}
      >
        <Pressable
          onPress={() => setPickerOpen(false)}
          className="flex-1 justify-end bg-black/30"
        >
          <Pressable
            onPress={(e) => e.stopPropagation()}
            className="max-h-[75%] rounded-t-[28px] bg-canvas p-5"
          >
            <Text className="mb-4 font-bold text-[20px] text-ink">
              Kies je paard
            </Text>
            <ScrollView>
              {horses.map((item) => (
                <Pressable
                  key={item.id}
                  accessibilityRole="button"
                  accessibilityState={{ selected: item.id === horse.id }}
                  onPress={() => {
                    selectHorse(item.id);
                    setPickerOpen(false);
                  }}
                  className="mb-2 rounded-2xl bg-white p-4"
                >
                  <Text className="font-semi text-[16px] text-ink">
                    {item.name}
                  </Text>
                </Pressable>
              ))}
            </ScrollView>
            <SafeAreaView edges={["bottom"]}>
              <Pressable
                onPress={() => setPickerOpen(false)}
                className="mt-2 rounded-full bg-mint-500 p-3"
              >
                <Text className="text-center font-bold text-white">
                  Sluiten
                </Text>
              </Pressable>
            </SafeAreaView>
          </Pressable>
        </Pressable>
      </Modal>
    </SafeAreaView>
  );
}

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
      <Text className="text-[10px] uppercase tracking-[1.3px] text-ink-50">
        {data.horse.name}&apos;s Plus-protocol
      </Text>
      <Text className="mt-3 font-bold text-[18px] text-ink">
        {protocol.statusLabel}
      </Text>
      <Text className="mt-1 text-[13px] text-ink-50">
        Dag {protocol.currentDay} · Week {protocol.currentWeek}
        {protocol.phaseLabel ? ` · ${protocol.phaseLabel}` : ""}
      </Text>
      <View className="mt-4 h-1.5 overflow-hidden rounded-full bg-ink-8">
        <View
          className="h-full bg-mint-500"
          style={{ width: `${protocol.progressPercent}%` }}
        />
      </View>
      <Text className="mt-4 font-semi text-[14px] text-mint-700">
        Ga verder met het protocol →
      </Text>
    </Pressable>
  );
}

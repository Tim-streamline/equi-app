import { LibraryThumbnail } from "@/components/library/LibraryThumbnail";
import { useEffect, useState, type ReactNode } from "react";
import {
  View,
  Text,
  ScrollView,
  Pressable,
  Modal,
  ActivityIndicator,
  TextInput,
  Alert,
  KeyboardAvoidingView,
  Platform,
} from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import {
  Check,
  X,
  ChevronLeft,
  ChevronRight,
  ShoppingBag,
  TriangleAlert,
  Leaf,
} from "lucide-react-native";
import { Button } from "@/components/ui/Button";
import { Avatar } from "@/components/ui/Avatar";
import { useTabBarPadding } from "@/hooks/useTabBarPadding";
import { IntakeEntryCard } from "@/components/intake/IntakeEntryCard";
import { useIntake } from "@/lib/intake/store";
import {
  useStoreMutations,
  useCurrentUser,
  useSupplementIntakesForDate,
} from "@/db/hooks";
import {
  useHorseDashboard,
  dashboardRequest,
  deviceTimezone,
} from "@/hooks/useHorseDashboard";
import {
  libraryPath,
  type DashboardProtocol,
  type DashboardPhase,
  type DashboardSupplement,
  type LibraryRecommendation,
} from "@/lib/horse-dashboard";

import { ProtocolAnalysis } from "@/components/protocol/ProtocolAnalysis";
import { CareAdvice } from "@/components/protocol/CareAdvice";
import { ProtocolTabs } from "@/components/protocol/ProtocolTabs";
import { protocolTab, type ProtocolTab } from "@/lib/protocol-tabs";

export default function ProtocolListScreen() {
  const { tab: tabParam, t: nonce } = useLocalSearchParams<{
    tab?: string;
    t?: string;
  }>();
  const [tab, setTab] = useState<ProtocolTab>(protocolTab(tabParam) ?? "vandaag");
  const [month, setMonth] = useState<string>();
  const { data, error, refresh, horseId } = useHorseDashboard(month);
  const user = useCurrentUser();
  const { state: intake } = useIntake();
  const [orders, setOrders] = useState<DashboardSupplement[] | null>(null);
  const [phase, setPhase] = useState<DashboardPhase | null>(null);
  const [weeklyOpen, setWeeklyOpen] = useState(false);
  const padBottom = useTabBarPadding();
  useEffect(() => {
    const requestedTab = protocolTab(tabParam);
    if (requestedTab) setTab(requestedTab);
  }, [tabParam, nonce]);
  useEffect(() => {
    setPhase(null);
    setOrders(null);
    setWeeklyOpen(false);
    setMonth(undefined);
  }, [horseId]);
  const protocol = data?.protocol;
  const sub =
    tab === "vandaag"
      ? protocol?.todayLabel
      : tab === "kalender"
        ? protocol?.calendar.label
        : tab === "voeding"
          ? "Basisvoeding"
          : tab === "zorg"
            ? "Zorgadviezen"
            : "Analyse";

  return (
    <SafeAreaView edges={["top"]} className="flex-1 bg-canvas">
      <View className="flex-row items-center justify-between gap-3 px-5 pb-3 pt-1">
        <View className="flex-1">
          <Text className="font-bold text-[26px] leading-[30px] text-ink">
            {data?.horse.name ?? "Protocol"}
          </Text>
          <Text className="mt-0.5 text-[13px] text-ink-50">{sub}</Text>
        </View>
        {protocol && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Open bestellijst"
            onPress={() => setOrders(protocol.orderItems)}
            className="h-[38px] w-[38px] items-center justify-center rounded-xl border border-ink-15 bg-white"
          >
            <ShoppingBag size={19} color="#127A79" />
          </Pressable>
        )}
        <Avatar
          initial={String(user.name ?? "")
            .charAt(0)
            .toUpperCase()}
          size={38}
        />
      </View>
      {!!error && (
        <Pressable
          onPress={() => void refresh()}
          className="mx-5 mb-3 rounded-xl bg-[#FFF3DF] p-3"
        >
          <Text className="text-[12px] text-[#7A5A16]">
            {data ? "Laatst opgehaalde gegevens. " : ""}
            {error} Tik om opnieuw te proberen.
          </Text>
        </Pressable>
      )}
      {!data && !error && <ActivityIndicator color="#18BAB0" />}
      {data && !protocol && (
        <ScrollView
          contentContainerStyle={{ padding: 20, paddingBottom: padBottom }}
        >
          {!intake.submittedAt ? (
            <IntakeEntryCard variant="standalone" />
          ) : (
            <View className="rounded-2xl bg-white p-5">
              <Text className="font-bold text-[16px] text-ink">
                Intake verzonden
              </Text>
              <Text className="mt-2 text-[13px] text-ink-50">
                Je protocol verschijnt hier zodra je therapeut het heeft
                gepubliceerd.
              </Text>
            </View>
          )}
        </ScrollView>
      )}
      {protocol && (
        <>
          <ProtocolTabs selected={tab} onSelect={setTab} />
          <ScrollView
            key={`${horseId}:${tab}`}
            contentContainerStyle={{
              paddingHorizontal: 20,
              paddingTop: 16,
              paddingBottom: padBottom,
            }}
          >
            {tab === "vandaag" && (
              <Today
                protocol={protocol}
                date={data.date}
                horseId={horseId}
                onOrder={setOrders}
                onWeekly={() => setWeeklyOpen(true)}
              />
            )}
            {tab === "kalender" && (
              <Calendar
                protocol={protocol}
                onMonth={setMonth}
                onPhase={setPhase}
              />
            )}
            {tab === "voeding" && (
              <Nutrition
                nutrition={protocol.nutrition}
                hasPlus={data.hasPlus}
              />
            )}
            {tab === "zorg" && <CareAdvice protocol={protocol} />}
            {tab === "analyse" && <ProtocolAnalysis analysis={protocol.analysis} />}
          </ScrollView>
          <Sheet
            visible={orders !== null}
            title="Bestellijst"
            onClose={() => setOrders(null)}
          >
            {orders?.length ? (
              <View className="gap-2">
                {orders.map((item) => (
                  <Supplement key={item.id} item={item} />
                ))}
              </View>
            ) : (
              <Text className="py-5 text-[14px] text-ink-50">
                Er staan geen producten op deze bestellijst.
              </Text>
            )}
          </Sheet>
          <Sheet
            visible={phase !== null}
            title={phase?.title ?? ""}
            subtitle={phase ? `${phase.weekLabel} · ${phase.statusLabel}` : ""}
            onClose={() => setPhase(null)}
          >
            {!!phase?.description && (
              <Text className="mb-5 text-[14px] leading-[23px] text-ink-70">
                {phase.description}
              </Text>
            )}
            {!!phase?.supplements.length && (
              <>
                <Label>Per kruid</Label>
                <View className="mt-3 gap-2">
                  {phase.supplements.map((item) => (
                    <Supplement key={item.id} item={item} />
                  ))}
                </View>
              </>
            )}
          </Sheet>
          <WeeklySheet
            visible={weeklyOpen}
            horseId={horseId}
            protocolId={protocol.id}
            onClose={() => setWeeklyOpen(false)}
            onSaved={refresh}
          />
        </>
      )}
    </SafeAreaView>
  );
}

function Card({ children }: { children: ReactNode }) {
  return (
    <View className="rounded-[20px] border border-ink-8 bg-white p-[18px]">
      {children}
    </View>
  );
}
function Label({ children }: { children: ReactNode }) {
  return (
    <Text className="font-semi text-[10px] uppercase tracking-[1.3px] text-ink-50">
      {children}
    </Text>
  );
}
function Supplement({ item }: { item: DashboardSupplement }) {
  return (
    <View className="rounded-2xl border border-ink-8 bg-white p-4">
      <View className="flex-row items-start justify-between gap-3">
        <Text className="flex-1 font-semi text-[14px] text-ink">
          {item.name}
        </Text>
        <Text className="max-w-[40%] font-bold text-[13px] text-mint-700">
          {item.dosage ?? "Dosering niet ingesteld"}
        </Text>
      </View>
      {!!item.description && (
        <Text className="mt-1 text-[12px] leading-[18px] text-ink-50">
          {item.description}
        </Text>
      )}
      {!!item.instructions && (
        <Text className="mt-1 text-[12px] leading-[18px] text-ink-70">
          {item.instructions}
        </Text>
      )}
      {!!item.frequencyLabel && (
        <Text className="mt-1 text-[11px] text-ink-50">
          {item.frequencyLabel}
        </Text>
      )}
    </View>
  );
}

function Today({
  protocol,
  date,
  horseId,
  onOrder,
  onWeekly,
}: {
  protocol: DashboardProtocol;
  date: string;
  horseId: string;
  onOrder: (items: DashboardSupplement[]) => void;
  onWeekly: () => void;
}) {
  const mutations = useStoreMutations();
  const localIntakes = useSupplementIntakesForDate(date);
  const [pending, setPending] = useState<string | null>(null);
  const toggle = async (item: DashboardSupplement) => {
    setPending(item.id);
    try {
      await mutations.toggleSupplementIntake(
        item.id,
        item.dosage,
        date,
        horseId,
      );
    } catch {
      Alert.alert("Niet opgeslagen", "Probeer het opnieuw.");
    } finally {
      setPending(null);
    }
  };
  return (
    <>
      {protocol.today.total > 0 && (
        <View className="mb-4">
          <Card>
            <View className="flex-row items-center justify-between">
              <View>
                <Text className="font-bold text-[16px] text-ink">
                  Vandaag afvinken
                </Text>
                <Text className="mt-1 text-[13px] text-ink-50">
                  {protocol.today.done} van {protocol.today.total} gedaan
                </Text>
              </View>
              <Text className="font-bold text-[15px] text-mint-700">
                {protocol.today.percentage}%
              </Text>
            </View>
            <View className="mt-3 h-1.5 overflow-hidden rounded-full bg-ink-8">
              <View
                className="h-full bg-mint-500"
                style={{ width: `${protocol.today.percentage}%` }}
              />
            </View>
          </Card>
        </View>
      )}
      <Label>Door de geweekte bijvoeding</Label>
      <View className="mb-4 mt-3 gap-2">
        {protocol.today.items.map((item) => {
          const local = localIntakes.find(
            (row) => row.protocolPhaseSupplementId === item.id,
          );
          const done = local ? !!local.done : !!item.done;
          return (
            <Pressable
              key={item.id}
              accessibilityRole="checkbox"
              accessibilityLabel={`${item.name}, ${item.dosage ?? "dosering niet ingesteld"}`}
              accessibilityState={{
                checked: done,
                disabled: pending === item.id,
              }}
              disabled={pending === item.id}
              onPress={() => void toggle(item)}
              className="flex-row items-center gap-3 rounded-[14px] border border-ink-8 bg-white px-3 py-3"
            >
              <View
                className={`h-[22px] w-[22px] items-center justify-center rounded-[7px] border-2 ${done ? "border-mint-500 bg-mint-500" : "border-ink-15"}`}
              >
                {done && <Check size={13} color="white" />}
              </View>
              <Text
                className={`flex-1 font-semi text-[14px] ${done ? "text-ink-50 line-through" : "text-ink"}`}
              >
                {item.name}
              </Text>
              <Text className="max-w-[35%] font-bold text-[13px] text-mint-700">
                {item.dosage ?? "Dosering niet ingesteld"}
              </Text>
            </Pressable>
          );
        })}
        {protocol.today.total === 0 && (
          <Text className="py-3 text-[14px] text-ink-50">
            Vandaag staat er niets gepland.
          </Text>
        )}
      </View>
      <View className="gap-3">
        {protocol.notifications.map((notice) => (
          <View
            key={notice.id}
            className="rounded-[20px] border border-[#E9D9B8] bg-[#FBF4E8] p-4"
          >
            <View className="flex-row items-start gap-2">
              <TriangleAlert size={18} color="#9B792F" />
              <View className="flex-1">
                <Text className="font-bold text-[14px] text-[#6C5426]">
                  {notice.title}
                </Text>
                <Text className="mt-2 text-[13px] leading-[19px] text-ink-70">
                  {notice.body}
                </Text>
              </View>
            </View>
            <View className="mt-2">
              {notice.items.map((item) => (
                <View
                  key={item.id}
                  className="flex-row justify-between gap-3 py-2"
                >
                  <Text className="flex-1 font-semi text-[13px] text-ink">
                    {item.name}
                  </Text>
                  <Text className="text-[13px] text-ink-70">{item.dosage}</Text>
                </View>
              ))}
            </View>
            <Pressable
              accessibilityRole="button"
              onPress={() =>
                notice.type === "weekly_update"
                  ? onWeekly()
                  : onOrder(notice.orderItems ?? notice.items)
              }
              className="mt-3 rounded-full border border-[#DCCBA8] py-3"
            >
              <Text className="text-center font-semi text-[13px] text-[#6C5426]">
                {notice.type === "weekly_update"
                  ? "Weekupdate invullen"
                  : "Bekijk de bestellijst"}
              </Text>
            </Pressable>
          </View>
        ))}
      </View>
    </>
  );
}

function Calendar({
  protocol,
  onMonth,
  onPhase,
}: {
  protocol: DashboardProtocol;
  onMonth: (month: string) => void;
  onPhase: (phase: DashboardPhase) => void;
}) {
  const [selected, setSelected] = useState<string>();
  const calendar = protocol.calendar;
  const colors = {
    default: ["#FFFFFF", "#E8ECEB", "#9EA6A5"],
    complete: ["#18BAB0", "#18BAB0", "#FFFFFF"],
    partial: ["#CFF2EB", "#A9DFD4", "#23796E"],
    missed: ["#F9E6C4", "#E8B864", "#8B621C"],
  };
  return (
    <>
      <Card>
        <View className="mb-4 flex-row items-center justify-between">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Vorige maand"
            onPress={() => onMonth(calendar.previousMonth)}
            className="p-2"
          >
            <ChevronLeft size={18} color="#536C6B" />
          </Pressable>
          <Text className="font-bold text-[15px] text-ink">
            {calendar.label}
          </Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Volgende maand"
            onPress={() => onMonth(calendar.nextMonth)}
            className="p-2"
          >
            <ChevronRight size={18} color="#536C6B" />
          </Pressable>
        </View>
        <View className="mb-2 flex-row">
          {["MA", "DI", "WO", "DO", "VR", "ZA", "ZO"].map((day) => (
            <Text
              key={day}
              className="flex-1 text-center text-[10px] text-ink-30"
            >
              {day}
            </Text>
          ))}
        </View>
        <View className="flex-row flex-wrap">
          {calendar.cells.map((cell, index) => {
            const tone = cell?.isToday
              ? ["#0B4A49", "#0B4A49", "#FFFFFF"]
              : colors[cell?.state ?? "default"];
            return (
              <View
                key={cell?.date ?? `blank-${index}`}
                style={{ width: "14.285714%", aspectRatio: 1, padding: 2 }}
              >
                {cell && (
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={`${cell.date}, ${cell.state}`}
                    accessibilityState={{ selected: selected === cell.date }}
                    onPress={() => setSelected(cell.date)}
                    className="flex-1 items-center justify-center rounded-xl"
                    style={{
                      backgroundColor: tone[0],
                      borderWidth: 1,
                      borderColor: selected === cell.date ? "#0B4A49" : tone[1],
                    }}
                  >
                    <Text
                      className="font-semi text-[13px]"
                      style={{ color: tone[2] }}
                    >
                      {cell.day}
                    </Text>
                  </Pressable>
                )}
              </View>
            );
          })}
        </View>
        <View className="mt-3 flex-row gap-3">
          {[
            ["#18BAB0", "Compleet"],
            ["#99E8DF", "Deels"],
            ["#E8A33C", "Gemist"],
          ].map(([color, label]) => (
            <View key={label} className="flex-row items-center gap-1">
              <View
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: color }}
              />
              <Text className="text-[10px] text-ink-50">{label}</Text>
            </View>
          ))}
        </View>
      </Card>
      <View className="mb-3 mt-5">
        <Label>Verloop van het protocol</Label>
      </View>
      <View className="gap-2">
        {protocol.phases.map((phase) => (
          <Pressable
            key={phase.id}
            accessibilityRole="button"
            accessibilityLabel={`${phase.title}, ${phase.statusLabel}`}
            onPress={() => onPhase(phase)}
            className={`flex-row items-center gap-2 rounded-2xl border p-4 ${phase.state === "active" ? "border-mint-500 bg-white" : "border-ink-8 bg-white/70"}`}
          >
            <View className="flex-1">
              <Text className="font-semi text-[14px] text-ink">
                {phase.title}
              </Text>
              <Text className="mt-1 text-[12px] text-ink-50">
                {phase.weekLabel}
              </Text>
            </View>
            <Text
              className={`max-w-[40%] rounded-full px-2 py-1 text-[10px] ${phase.state === "active" ? "bg-mint-50 text-mint-700" : "bg-ink-8 text-ink-50"}`}
            >
              {phase.statusLabel}
            </Text>
            <ChevronRight size={15} color="#97A3A2" />
          </Pressable>
        ))}
      </View>
    </>
  );
}

function LibraryLink({
  title,
  description,
  item,
  search,
}: {
  title: string;
  description: string;
  item: LibraryRecommendation | null;
  search: string;
}) {
  return (
    <Pressable
      accessibilityRole="link"
      onPress={() =>
        router.push(
          item
            ? (libraryPath(item) as any)
            : {
                pathname: "/(tabs)/(pager)/library",
                params: { q: search, t: String(Date.now()) },
              },
        )
      }
      className="flex-row items-center gap-3 rounded-[18px] border border-ink-8 bg-white p-4"
    >
      <LibraryThumbnail uri={item?.heroImageUrl} format={item?.format} style={{ width: 64 }} />
      <View className="flex-1">
        <Text className="font-semi text-[13px] text-ink">{title}</Text>
        <Text className="mt-1 text-[11px] leading-[16px] text-ink-50">
          {description}
        </Text>
      </View>
      <ChevronRight size={16} color="#97A3A2" />
    </Pressable>
  );
}
function Nutrition({
  nutrition,
  hasPlus,
}: {
  nutrition: DashboardProtocol["nutrition"];
  hasPlus: boolean;
}) {
  const roughage = nutrition.roughage;
  return (
    <View className="gap-4">
      <View className="rounded-[22px] bg-[#105C5B] p-5">
        <View className="mb-3 flex-row gap-2">
          <Leaf size={15} color="#99E8DF" />
          <Text className="font-semi text-[10px] uppercase tracking-[1px] text-[#99E8DF]">
            Ruwvoer, de basis
          </Text>
        </View>
        {!!roughage.rangeLabel && (
          <Text className="font-bold text-[25px] text-white">
            {roughage.rangeLabel}{" "}
            <Text className="text-[11px]">per 24 uur</Text>
          </Text>
        )}
        <Text className="mt-2 text-[13px] leading-[21px] text-white/85">
          {roughage.description}
        </Text>
        <View className="mt-4 flex-row gap-2 border-t border-white/15 pt-3">
          {[
            ["Suiker", roughage.sugar],
            ["Eiwit", roughage.protein],
          ].map(([label, value]) => (
            <View key={label} className="flex-1 rounded-xl bg-white/10 p-3">
              <Text className="text-[9px] uppercase text-white/65">
                {label}
              </Text>
              <Text className="mt-1 font-bold text-[14px] text-white">
                {value}
              </Text>
            </View>
          ))}
        </View>
      </View>
      <LibraryLink
        title="Hooi laten analyseren of zelf testen"
        description={`Bekijk hoe je een monster neemt en de uitslag beoordeelt.${hasPlus ? " Zonder credit met Plus." : ""}`}
        item={nutrition.hayLibraryItem}
        search="Hooianalyse"
      />
      {nutrition.feeds.length > 0 && (
        <Card>
          <Text className="mb-3 font-bold text-[16px] text-ink">
            Bijvoeding
          </Text>
          <View className="gap-4">
            {nutrition.feeds.map((feed) => (
              <View key={feed.id} className="flex-row items-start gap-2">
                {feed.status === "continue" ? (
                  <Check size={17} color="#18BAB0" />
                ) : (
                  <X size={17} color="#CB655D" />
                )}
                <View className="flex-1">
                  <Text className="font-semi text-[14px] text-ink">
                    {feed.name}
                    {feed.dosage ? ` · ${feed.dosage}` : ""}
                  </Text>
                  <Text
                    className={`mt-1 font-semi text-[12px] ${feed.status === "continue" ? "text-mint-700" : "text-[#B45E56]"}`}
                  >
                    {feed.status === "continue" ? "Doorgaan" : "Stoppen"}
                  </Text>
                  {!!feed.note && (
                    <Text className="mt-1 text-[12px] leading-[19px] text-ink-50">
                      {feed.note}
                    </Text>
                  )}
                </View>
              </View>
            ))}
          </View>
        </Card>
      )}
      <Card>
        <Text className="font-bold text-[16px] text-ink">Water</Text>
        <Text className="mt-2 text-[13px] leading-[20px] text-ink-70">
          {nutrition.water.types.join(" · ") ||
            "Nog geen drinkwater ingevuld in de intake."}
        </Text>
        {nutrition.water.needsAnalysis && (
          <>
            <Text className="mb-3 mt-3 text-[13px] leading-[20px] text-ink-50">
              {nutrition.water.advice}
            </Text>
            <LibraryLink
              title="Water laten analyseren"
              description={
                hasPlus
                  ? "Bekijk de uitleg zonder credit met Plus."
                  : "Bekijk de uitleg in de bibliotheek."
              }
              item={nutrition.waterLibraryItem}
              search="Wateranalyse"
            />
          </>
        )}
      </Card>
    </View>
  );
}
function Sheet({
  visible,
  title,
  subtitle,
  children,
  onClose,
}: {
  visible: boolean;
  title: string;
  subtitle?: string;
  children: ReactNode;
  onClose: () => void;
}) {
  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      onRequestClose={onClose}
    >
      <Pressable onPress={onClose} className="flex-1 justify-end bg-black/30">
        <Pressable
          onPress={(event) => event.stopPropagation()}
          className="max-h-[82%] rounded-t-[28px] bg-canvas px-5 pt-3"
        >
          <View className="mb-4 h-1 w-10 self-center rounded-full bg-ink-15" />
          <Text className="font-bold text-[21px] text-ink">{title}</Text>
          {!!subtitle && (
            <Text className="mt-1 text-[12px] text-ink-50">{subtitle}</Text>
          )}
          <ScrollView
            className="my-4"
            contentContainerStyle={{ paddingBottom: 8 }}
          >
            {children}
          </ScrollView>
          <SafeAreaView edges={["bottom"]} className="pb-3">
            <Button title="Sluiten" onPress={onClose} />
          </SafeAreaView>
        </Pressable>
      </Pressable>
    </Modal>
  );
}
function WeeklySheet({
  visible,
  horseId,
  protocolId,
  onClose,
  onSaved,
}: {
  visible: boolean;
  horseId: string;
  protocolId: string;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (!note.trim() || busy) return;
    setBusy(true);
    try {
      await dashboardRequest(`/api/horses/${horseId}/weekly-update`, {
        protocol_id: protocolId,
        note: note.trim(),
        timezone: deviceTimezone(),
      });
      setNote("");
      await onSaved();
      onClose();
    } catch (error) {
      Alert.alert(
        "Weekupdate niet opgeslagen",
        error instanceof Error ? error.message : "Probeer het opnieuw.",
      );
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      onRequestClose={onClose}
    >
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : "height"}
        className="flex-1 justify-end bg-black/30"
      >
        <SafeAreaView
          edges={["bottom"]}
          className="rounded-t-[28px] bg-canvas p-5"
        >
          <Text className="mb-3 font-bold text-[21px] text-ink">
            Weekupdate invullen
          </Text>
          <TextInput
            accessibilityLabel="Weekupdate"
            multiline
            value={note}
            onChangeText={setNote}
            placeholder="Hoe gaat het met je paard deze week?"
            maxLength={10000}
            textAlignVertical="top"
            className="mb-4 min-h-[130px] rounded-2xl border border-ink-15 bg-white p-4 text-[15px] text-ink"
          />
          <Button
            title={busy ? "Opslaan…" : "Weekupdate opslaan"}
            onPress={() => void save()}
            disabled={busy || !note.trim()}
          />
          <Pressable disabled={busy} onPress={onClose} className="mt-3 py-2">
            <Text className="text-center text-[14px] text-ink-50">
              Annuleren
            </Text>
          </Pressable>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </Modal>
  );
}

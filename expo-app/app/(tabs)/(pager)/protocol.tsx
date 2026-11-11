import { PurchasedProtocols } from '@/components/intake/PurchasedProtocols';
import { KeyboardTextInput as TextInput, KeyboardViewport as KeyboardAvoidingView, KeyboardScrollView } from '@/components/ui/KeyboardForm';
import { LibraryThumbnail } from "@/components/library/LibraryThumbnail";
import { useEffect, useMemo, useRef, useState, type ReactNode, type ComponentProps } from "react";
import {
  View,
  Text,
  ScrollView,
  Pressable,
  Modal,
  ActivityIndicator,
  Alert,
  Platform,
  useWindowDimensions,
} from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import {
  Check,
  X,
  ChevronRight,
  ShoppingBag,
  TriangleAlert,
  Leaf,
} from "lucide-react-native";
import { Button } from "@/components/ui/Button";
import { useTabBarPadding } from "@/hooks/useTabBarPadding";
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

import { ProtocolPhaseContent } from '@/components/protocol/ProtocolPhaseContent';
import { ProtocolCalendar } from '@/components/protocol/ProtocolCalendar';
import { phaseOrderItems, phaseSubtitle } from '@/lib/protocol-calendar';
import { ProtocolToday } from '@/components/protocol/ProtocolToday';
import { ProtocolAnalysis } from "@/components/protocol/ProtocolAnalysis";
import { ProtocolOrderList } from "@/components/protocol/ProtocolOrderList";
import { scopeOrderItems, type OrderScope } from "@/lib/protocol-order-scope";
import { CareAdvice } from "@/components/protocol/CareAdvice";
import { ProtocolTabs } from "@/components/protocol/ProtocolTabs";
import { PROTOCOL_SCALE as S, PROTOCOL_TEXT as T } from "@/components/protocol/styles";
import { EYEBROW, PLAIN_CARD } from "@/components/ui/styles";
import { HorseSwitcher } from "@/components/horse/HorseSwitcher";
import { OfflineStrip } from "@/components/ui/OfflineStrip";
import { HEADER_ICON } from "@/components/ui/AppHeader";
import { IconButton } from "@/components/ui/IconButton";
import { useHeaderAction } from "@/components/navigation/headerActions";
import { accessibleNotificationPhase } from "@/lib/protocol-notifications";
import { useDb } from "@/db/provider";
import { protocolTab, type ProtocolTab } from "@/lib/protocol-tabs";

export default function ProtocolListScreen() {
  const { tab: tabParam, t: nonce, horseId: requestedHorse, protocolId: requestedProtocol, phaseIds: requestedPhases, horsePicker, orderList: requestedOrderList } = useLocalSearchParams<{
    orderList?: string;
    horsePicker?: string;
    tab?: string;
    t?: string;
    horseId?: string;
    protocolId?: string;
    phaseIds?: string;
  }>();
  const [tab, setTab] = useState<ProtocolTab>(protocolTab(tabParam) ?? "vandaag");
  const { selectHorse } = useDb();
  const handledLink = useRef<string | null>(null);
  const handledOrderLink = useRef<string | null>(null);
  const [month, setMonth] = useState<string>();
  const { data, error, refresh, horseId } = useHorseDashboard(month);
  // 'all' is the protocol's full order list; otherwise only a phase's or notice's share of it.
  const [orders, setOrders] = useState<OrderScope | null>(null);
  const orderSelections = (items: DashboardSupplement[], label?: string): OrderScope =>
    ({ selections: items.map((item) => ({ id: item.id, phaseId: item.phaseId })), label });
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
  useEffect(() => {
    if (requestedOrderList !== '1' || !protocol || horseId !== requestedHorse || protocol.id !== requestedProtocol) return;
    const key = `${requestedHorse}:${requestedProtocol}:${nonce}`;
    if (handledOrderLink.current === key) return;
    handledOrderLink.current = key;
    setOrders('all');
  }, [requestedOrderList, protocol, horseId, requestedHorse, requestedProtocol, nonce]);
  const openPhase = (selected: DashboardPhase) => {
    setPhase(selected);
  };
  useEffect(() => {
    if (requestedHorse) selectHorse(requestedHorse);
  }, [requestedHorse, selectHorse]);
  useEffect(() => {
    // Resolve links against this horse's current server response, never stale phase data.
    if (!requestedPhases || !protocol || horseId !== requestedHorse || protocol.id !== requestedProtocol) return;
    const key = `${requestedHorse}:${requestedProtocol}:${requestedPhases}:${nonce}`;
    if (handledLink.current === key) return;
    const selected = accessibleNotificationPhase(protocol, requestedProtocol, requestedPhases);
    if (!selected) return;
    handledLink.current = key;
    setTab("kalender");
    setPhase(selected);
  }, [protocol, horseId, requestedHorse, requestedProtocol, requestedPhases, nonce]);
  // Keep an open sheet in sync when a therapist changes the schedule or content.
  const visiblePhase = phase ? protocol?.phases.find((item) => item.id === phase.id) : null;

  const visibleOrders = scopeOrderItems(protocol?.orderItems, orders);
  const firstPhase = protocol?.phases[0];
  const linkedPhases = protocol?.phases.filter((item) => item.accessible && requestedPhases?.split(',').includes(item.id)) ?? [];

  // The selected horse and the order list live in the shared header, only while Protocol is visible.
  const hasOrders = !!protocol;
  const headerAction = useMemo(() => (
    <View className="flex-row items-center">
      <HorseSwitcher openSignal={horsePicker} />
      {hasOrders && (
        <IconButton accessibilityLabel="Open bestellijst" hitSlop={0} onPress={() => setOrders("all")}>
          <ShoppingBag size={22} color={HEADER_ICON} />
        </IconButton>
      )}
    </View>
  ), [horsePicker, hasOrders]);
  useHeaderAction(headerAction);

  return (
    <View className="flex-1 bg-canvas">
      {!!error && <OfflineStrip message={error} stale={!!data} onRetry={() => void refresh()} />}
      {!data && !error && <ActivityIndicator color="#18BAB0" />}
      {!protocol && (
        <ScrollView
          contentContainerStyle={{ padding: 20, paddingTop: 16, paddingBottom: padBottom }}
        >
          <PurchasedProtocols />
          {!horseId && !!data && (
            <View className={PLAIN_CARD}>
              <Text className={`mb-2 ${T.title}`}>
                Nog geen paard toegevoegd
              </Text>
              <Text className={`mb-4 ${T.body}`}>
                Voeg eerst je paard toe. Daarna kun je hier een persoonlijk protocol voor je paard volgen.
              </Text>
              <Button title="Paard toevoegen" onPress={() => router.push('/onboarding/add-horse')} />
            </View>
          )}
          {!!horseId && data?.horse.id === horseId && data.showPlusUpsell && (
            <Pressable
              accessibilityRole="link"
              accessibilityLabel={`Protocol kopen voor ${data.horse.name}`}
              onPress={() => router.push('/protocol-purchase')}
              className="rounded-[22px] bg-[#FFF0EC] p-5"
            >
              <Text className="font-semi text-[17px] leading-[23px] text-ink">
                Een persoonlijk protocol voor {data.horse.name}
              </Text>
              <Text className="mt-2 text-[14px] leading-[22px] text-ink-70">
                Koop een persoonlijk protocol voor je paard, afgestemd op jouw paard, zijn klachten en jullie doelen.
              </Text>
              <Text className="mt-3 font-semi text-[14px] text-teal-600">
                Protocol kopen →
              </Text>
            </Pressable>
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
              protocol.preparing ? <ProtocolPreparation key={`${horseId}:${protocol.id}`}
                protocol={protocol} horseId={horseId} horseName={data.horse.name}
                onOrder={() => setOrders(firstPhase ? { phaseId: firstPhase.id, label: firstPhase.title } : 'all')} onRefresh={refresh} /> : <Today
                protocol={protocol}
                date={data.date}
                horseId={horseId}
                onOrder={(items, label) => setOrders(orderSelections(items, label))}
                onPhase={openPhase}
                onWeekly={() => setWeeklyOpen(true)}
              />
            )}
            {tab === "kalender" && (
              <Calendar
                key={`${horseId}:${protocol.id}`}
                protocol={protocol}
                today={data.date}
                onOrder={phaseIds => setOrders(phaseIds ? { phaseIds } : 'all')}
                onMonth={setMonth}
                onPhase={openPhase}
                horseId={horseId}
                onRefresh={refresh}
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
            subtitle={orders && orders !== 'all' && orders.label ? `Voor ${orders.label}` : undefined}
            onClose={() => setOrders(null)}
          >
            <ProtocolOrderList items={visibleOrders} discount={protocol.orderDiscount} />
            {orders !== null && orders !== 'all' && (
              <Button variant="ghost" title="Bekijk volledige bestellijst" className="mt-4" onPress={() => setOrders('all')} />
            )}
          </Sheet>
          <Sheet
            visible={!!visiblePhase}
            title={visiblePhase?.title ?? ""}
            subtitle={visiblePhase ? phaseSubtitle(visiblePhase) : ""}
            onClose={() => setPhase(null)}
            footer={visiblePhase ? <Button title="Bekijk bestellijst" onPress={() => { setOrders({ phaseIds: [visiblePhase.id] }); setPhase(null); }} /> : undefined}
          >
            {linkedPhases.length > 1 && (
              <View className="mb-4 gap-2">
                {linkedPhases.map((item) => <Button key={item.id} variant={item.id === visiblePhase?.id ? "primary" : "ghost"} title={item.title} onPress={() => openPhase(item)} />)}
              </View>
            )}
            {visiblePhase && <ProtocolPhaseContent key={visiblePhase.id} phase={visiblePhase} date={data.date} orderItems={protocol.orderItems} onRefresh={refresh} />}
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
    </View>
  );
}

function ProtocolPreparation({ protocol, horseId, horseName, onOrder, onRefresh }: {
  protocol: DashboardProtocol;
  horseId: string;
  horseName?: string;
  onOrder: () => void;
  onRefresh: () => Promise<void>;
}) {
  const [confirm, setConfirm] = useState(false);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const busy = useRef(false);
  const start = async () => {
    if (busy.current) return;
    busy.current = true;
    setStarting(true);
    setError(null);
    try {
      await dashboardRequest(`/api/horses/${horseId}/protocol-start`, {
        protocol_id: protocol.id, confirmed: true, timezone: deviceTimezone(),
      });
      await onRefresh();
      setConfirm(false);
    } catch {
      setError('Je protocol kon niet worden gestart. Controleer je verbinding en probeer het opnieuw.');
    } finally {
      busy.current = false;
      setStarting(false);
    }
  };
  return <View className="gap-5">
    <Text accessibilityRole="header" className={T.title}>Je herstelplan{horseName ? ` voor ${horseName}` : ''} staat klaar</Text>
    <View className="gap-4">
      <Text className={T.lead}>Je herstelplan bestaat uit adviezen over <Text className="font-semi text-ink">voeding, beweging, huisvesting en management</Text>, plus een <Text className="font-semi text-ink">herstelprotocol</Text> waarmee je het lichaam stap voor stap ondersteunt bij herstel van binnenuit.</Text>
      <Text className={T.lead}>De verschillende onderdelen zijn <Text className="font-semi text-ink">bewust op elkaar afgestemd</Text>. Het resultaat hangt daarom af van hoe consequent je <Text className="font-semi text-ink">álle adviezen opvolgt</Text>.</Text>
      <Text className={T.lead}>Bekijk eerst welke kruiden en andere producten je voor de <Text className="font-semi text-ink">eerste fase</Text> nodig hebt en zorg dat je die <Text className="font-semi text-ink">in huis hebt voordat je begint</Text>. Daarna kun je het protocol starten.</Text>
    </View>
    <View className={PLAIN_CARD}>
      <Text className={`mb-2 ${T.cardTitle}`}>Wat heb je nodig voor fase 1?</Text>
      <Text className={`mb-4 ${T.lead}`}>Bekijk welke producten en hoeveelheden je voor de eerste fase nodig hebt.</Text>
      <Pressable accessibilityRole="button" accessibilityLabel="Open bestellijst voor fase 1" onPress={onOrder} className="min-h-[44px] justify-center">
        <Text className={T.link}>Bekijk bestellijst voor fase 1 →</Text>
      </Pressable>
    </View>
    <View className="gap-4 rounded-[22px] bg-mint-50 p-5">
      <Text className={T.cardTitle}>Heb je alles in huis?</Text>
      <Text className={T.lead}>Start je protocol op de dag dat je daadwerkelijk met alle producten uit de eerste fase begint. Klik die dag op de button hieronder.</Text>
      <Button title="Ik start vandaag" className="mt-1" onPress={() => { setError(null); setConfirm(true); }} />
    </View>
    <Sheet visible={confirm} title="Vandaag wordt dag 1 van je protocol" onClose={() => { if (!busy.current) setConfirm(false); }}>
      <Text className={`mb-5 ${T.body}`}>Weet je zeker dat je vandaag wilt beginnen?</Text>
      {!!error && <Text accessibilityRole="alert" className="mb-4 text-[14px] text-danger">{error}</Text>}
      <View className="gap-3">
        <Button title={starting ? 'Protocol starten…' : 'Ja, start mijn protocol'} disabled={starting} onPress={() => void start()} />
        <Button title="Nog niet" variant="ghost" disabled={starting} onPress={() => setConfirm(false)} />
      </View>
    </Sheet>
  </View>;
}

function Today({
  protocol,
  date,
  horseId,
  onOrder,
  onPhase,
  onWeekly,
}: {
  protocol: DashboardProtocol;
  date: string;
  horseId: string;
  onOrder: (items: DashboardSupplement[], label?: string) => void;
  onPhase: (phase: DashboardPhase) => void;
  onWeekly: () => void;
}) {
  return (
    <>
      <ProtocolToday protocol={protocol} date={date} horseId={horseId} onPhase={onPhase} SheetComponent={Sheet} />
      <View className="gap-3">
        {protocol.notifications.map((notice) => (
          <View
            key={notice.id}
            className="rounded-[22px] border border-[#E9D9B8] bg-[#FBF4E8] p-5"
          >
            <View className="flex-row items-start gap-2">
              <TriangleAlert size={18} color="#9B792F" style={{ marginTop: 2 }} />
              <View className="flex-1">
                <Text className={`${S.section} text-[#6C5426]`}>
                  {notice.title}
                </Text>
                <Text className={`mt-2 ${T.secondary}`}>
                  {notice.body}
                </Text>
              </View>
            </View>
            <View className="mt-3">
              {notice.items.map((item) => (
                <View
                  key={item.id}
                  className="flex-row flex-wrap justify-between gap-x-3 py-2"
                >
                  <Text className={T.rowTitle}>
                    {item.name}
                  </Text>
                  <Text className={T.row}>{item.buildupLabel ? `Volledige dosis: ${item.dosage} · met opbouw` : item.dosage}</Text>
                </View>
              ))}
            </View>
            <View className="mt-4 gap-3">
              {notice.type === "next_phase" && notice.phaseId && (
                <Button variant="ghost" title="Bekijk de fase" onPress={() => {
                  const selected = protocol.phases.find((item) => item.id === notice.phaseId);
                  if (selected) onPhase(selected);
                }} />
              )}
              <Button
                variant="ghost"
                title={notice.type === "weekly_update" ? "Weekupdate invullen" : "Bekijk bestellijst"}
                onPress={() =>
                  notice.type === "weekly_update"
                    ? onWeekly()
                    : onOrder(notice.orderItems ?? notice.items,
                      protocol.phases.find((item) => item.id === notice.phaseId)?.title)
                }
              />
            </View>
          </View>
        ))}
      </View>
    </>
  );
}

function Calendar(props: Omit<ComponentProps<typeof ProtocolCalendar>, 'SheetComponent'>) {
  return <ProtocolCalendar {...props} SheetComponent={Sheet} />;
}

function LibraryLink({
  title,
  description,
  item,
  search,
  selection,
  nested,
}: {
  title: string;
  description: string;
  item: LibraryRecommendation | null;
  search: string;
  selection?: boolean;
  /** Inside a white card: outlined, since a white tile would have no visible edge. */
  nested?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="link"
      onPress={() =>
        router.push(
          selection
            ? "/(tabs)/library/selection/hay-analysis"
            : item
            ? (libraryPath(item) as any)
            : {
                pathname: "/(tabs)/(pager)/library",
                params: { q: search, t: String(Date.now()) },
              },
        )
      }
      className={`flex-row items-center gap-3 p-4 ${nested ? 'rounded-2xl border border-ink-8' : 'rounded-[22px] bg-white'}`}
    >
      <LibraryThumbnail uri={item?.heroImageUrl} format={item?.format} style={{ width: 64 }} />
      <View className="flex-1">
        <Text className={T.section}>{title}</Text>
        <Text className={`mt-1 ${T.meta}`}>
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
          <Leaf size={16} color="#99E8DF" />
          <Text className={`${EYEBROW} text-[#99E8DF]`}>
            Ruwvoer, de basis
          </Text>
        </View>
        {!!roughage.rangeLabel && (
          <Text className="font-bold text-[25px] leading-[31px] text-white">
            {roughage.rangeLabel}{" "}
            <Text className="font-sans text-[14px]">per 24 uur</Text>
          </Text>
        )}
        <Text className={`mt-2 ${S.body} text-white/85`}>
          {roughage.description}
        </Text>
        <View className="mt-4 flex-row gap-2 border-t border-white/15 pt-3">
          {[
            ["Suiker", roughage.sugar],
            ["Eiwit", roughage.protein],
          ].map(([label, value]) => (
            <View key={label} className="flex-1 rounded-xl bg-white/10 p-3">
              <Text className={`${EYEBROW} text-white/70`}>
                {label}
              </Text>
              <Text className="mt-1 font-bold text-[16px] text-white">
                {value}
              </Text>
            </View>
          ))}
        </View>
      </View>
      <LibraryLink
        title="Hooi laten analyseren of zelf testen"
        description={`Bekijk hoe je een monster neemt en de uitslag beoordeelt.${hasPlus ? " Inbegrepen bij je abonnement." : ""}`}
        item={nutrition.hayLibraryItem}
        search="Hooianalyse"
        selection
      />
      {nutrition.feeds.length > 0 && (
        <View className={PLAIN_CARD}>
          <Text className={`mb-4 ${T.cardTitle}`}>
            Actuele voerproducten
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
                  <Text className={T.section}>
                    {feed.name}
                    {feed.dosage ? ` · ${feed.dosage}` : ""}
                  </Text>
                  <Text
                    className={`mt-1 font-semi text-[14px] ${feed.status === "continue" ? "text-teal-600" : "text-[#B45E56]"}`}
                  >
                    {feed.status === "continue" ? "Doorgaan" : "Stoppen"}
                  </Text>
                  {!!feed.note && (
                    <Text className={`mt-1 ${T.secondary}`}>
                      {feed.note}
                    </Text>
                  )}
                </View>
              </View>
            ))}
          </View>
        </View>
      )}
      {(nutrition.advice ?? []).map((advice) => (
        <View key={advice.id} className={PLAIN_CARD}>
          <Text className={T.cardTitle}>{advice.title}</Text>
          {!!advice.description && <Text className={`mt-2 ${T.body}`}>{advice.description}</Text>}
        </View>
      ))}
      <View className={PLAIN_CARD}>
        <Text className={T.cardTitle}>Water</Text>
        <Text className={`mt-2 ${T.body}`}>
          {nutrition.water.types.join(" · ") ||
            "Nog geen drinkwater ingevuld in de intake."}
        </Text>
        {nutrition.water.needsAnalysis && (
          <>
            <Text className={`mb-3 mt-3 ${T.body}`}>
              {nutrition.water.advice}
            </Text>
            <LibraryLink
              title="Water laten analyseren"
              description={
                hasPlus
                  ? "Bekijk de uitleg met je abonnement."
                  : "Bekijk de uitleg in de bibliotheek."
              }
              item={nutrition.waterLibraryItem}
              search="Wateranalyse"
              nested
            />
          </>
        )}
      </View>
    </View>
  );
}
function Sheet({
  visible,
  title,
  subtitle,
  children,
  onClose,
  footer,
}: {
  visible: boolean;
  title: string;
  subtitle?: string;
  children: ReactNode;
  onClose: () => void;
  footer?: ReactNode;
}) {
  const { height } = useWindowDimensions();
  const insets = useSafeAreaInsets();

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      onRequestClose={onClose}
      statusBarTranslucent
      navigationBarTranslucent
    >
      <View style={{ flex: 1, justifyContent: "flex-end" }}>
        <Pressable
          testID="protocol-sheet-backdrop"
          accessibilityRole="button"
          accessibilityLabel="Sluit details"
          onPress={onClose}
          style={{ position: "absolute", top: 0, right: 0, bottom: 0, left: 0 }}
          className="bg-black/30 active:opacity-100"
        />
        <View
          testID="protocol-sheet-panel"
          accessibilityViewIsModal
          style={{ height: Math.max(0, height - insets.top - 12), overflow: "hidden" }}
          className="rounded-t-[28px] bg-canvas"
        >
          <View style={{ flexShrink: 0, paddingTop: 12, paddingBottom: 16 }}>
            <View testID="protocol-sheet-handle" className="h-1 w-10 self-center rounded-full bg-ink-15" />
          </View>
          <ScrollView
            testID="protocol-sheet-scroll"
            style={{ flex: 1, minHeight: 0 }}
            nestedScrollEnabled
            contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 24 }}
          >
            <View className="flex-row items-center gap-3">
              <Text accessibilityRole="header" className={`flex-1 ${T.title}`}>{title}</Text>
              <Pressable accessibilityRole="button" accessibilityLabel="Sluiten" onPress={onClose} className="min-h-[44px] min-w-[44px] items-center justify-center"><X size={22} color="#1B2A2A" /></Pressable>
            </View>
            {!!subtitle && (
              <Text className={`mt-1 ${T.secondary}`}>{subtitle}</Text>
            )}
            <View className="mt-4">
              {children}
            </View>
          </ScrollView>
          <View
            testID="protocol-sheet-footer"
            style={{ flexShrink: 0, paddingHorizontal: 20, paddingTop: 12, paddingBottom: Math.max(insets.bottom, 12) }}
          >
            {footer ?? <Button title="Sluiten" onPress={onClose} />}
          </View>
        </View>
      </View>
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
          style={{ maxHeight: '100%', flexShrink: 1 }}
          className="rounded-t-[28px] bg-canvas"
        >
          <KeyboardScrollView style={{ flexGrow: 0 }} contentContainerStyle={{ padding: 20 }}>
          <Text className={`mb-3 ${T.title}`}>
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
            className="mb-4 min-h-[130px] rounded-2xl border border-ink-15 bg-white p-4 font-sans text-[16px] text-ink"
          />
          <Button
            title={busy ? "Opslaan…" : "Weekupdate opslaan"}
            onPress={() => void save()}
            disabled={busy || !note.trim()}
          />
          <Button variant="text" title="Annuleren" disabled={busy} onPress={onClose} className="mt-2" />
          </KeyboardScrollView>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </Modal>
  );
}

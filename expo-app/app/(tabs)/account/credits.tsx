import { TemporaryCreditButton } from '@/components/credits/TemporaryCreditButton';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Platform, ScrollView, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { SafeAreaView } from 'react-native-safe-area-context';
import { libraryRequest, useLibraryResource } from '@/hooks/useLibraryResource';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { libraryPath } from '@/lib/library';
import { creditDate as date } from '@/lib/credits';

type Bundle = { id: string; label: string | null; credits: number; price_cents: number; currency: string };
type CreditSummary = {
  balance: number; membership: number; purchased: number; other: number; membershipCap: number;
  monthlyCredits: number; nextExpiry: string | null; nextRenewal: string | null; endsAt: string | null;
  expiring: { credits: number; date: string; urgent: boolean }[];
  hasBasic: boolean; checkoutAvailable: boolean; validityMonths: number; bundles: Bundle[];
  history: { id: string; amount: number; type: string; description: string; created_at: string }[];
};
const types: Record<string, string> = { membership: 'Basic', purchased: 'Bijgekocht', spent: 'Besteed', expired: 'Verlopen', refund: 'Terugboeking', adjustment: 'Correctie', promotional: 'Actie' };
// A stable request key survives retry of an uncertain checkout response.
const requestKey = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.floor(Math.random() * 16); return (c === 'x' ? r : (r & 3) | 8).toString(16); });

export default function CreditsScreen() {
  const params = useLocalSearchParams<{ itemId?: string; format?: string; buy?: string; orderId?: string }>();
  const padBottom = useTabBarPadding();
  const { data, error, refresh, update } = useLibraryResource<CreditSummary>('/credits');
  const [buying, setBuying] = useState(params.buy === '1');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [orderId, setOrderId] = useState(params.orderId ?? '');
  const pending = useRef(false);
  const keys = useRef<Record<string, string>>({});
  const checking = useRef(false);

  async function checkOrder() {
    if (!orderId || checking.current) return;
    checking.current = true;
    try {
      const order = await libraryRequest<{ status: string; itemId: string | null }>(`/credits/orders/${encodeURIComponent(orderId)}`);
      if (order.status === 'paid') {
        setOrderId(''); setMessage('Je betaling is gelukt. De credits zijn toegevoegd.'); await refresh();
        if (order.itemId) router.replace(libraryPath({ id: order.itemId, format: params.format ?? 'article' }) as any);
      } else if (['failed', 'canceled', 'expired', 'reversed'].includes(order.status)) {
        setOrderId(''); keys.current = {}; setMessage('De betaling is niet afgerond. Er zijn geen credits toegevoegd.');
      } else setMessage('De betaling is nog niet bevestigd. Je saldo wordt bijgewerkt zodra de betaling is ontvangen.');
    } catch (cause) { setMessage(cause instanceof Error ? cause.message : 'Betaling controleren is niet gelukt.'); }
    finally { checking.current = false; }
  }
  useEffect(() => {
    if (!orderId) return;
    void checkOrder();
    const subscription = AppState.addEventListener('change', state => { if (state === 'active') void checkOrder(); });
    return () => subscription.remove();
  }, [orderId]);

  async function purchase(bundle: Bundle) {
    if (pending.current) return;
    pending.current = true; setBusy(true); setMessage('');
    try {
      const result = await libraryRequest<{ orderId: string; checkoutUrl: string }>('/credits/purchase', 'POST', undefined, {
        bundleId: bundle.id, requestKey: keys.current[bundle.id] ??= requestKey(), itemId: params.itemId ?? null,
      });
      setOrderId(result.orderId);
      if (Platform.OS === 'web') {
        // Same-tab navigation avoids popup blockers; the provider returns to this route.
        globalThis.location.assign(result.checkoutUrl);
      } else await WebBrowser.openBrowserAsync(result.checkoutUrl);
    } catch (cause) { setMessage(cause instanceof Error ? cause.message : 'Betaling starten is niet gelukt.'); }
    finally { pending.current = false; setBusy(false); }
  }
  async function cancelBasic() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setMessage('');
    try { await libraryRequest('/credits/cancel-basic', 'POST'); await refresh(); setConfirmCancel(false); }
    catch (cause) { setMessage(cause instanceof Error ? cause.message : 'Opzeggen is niet gelukt.'); }
    finally { pending.current = false; setBusy(false); }
  }

  return <View className="flex-1"><SafeAreaView edges={['top']} style={{ flex: 1 }}>
    <SubHeader title="Credits" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/account')} />
    <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: padBottom }}>
      {!data ? <View className="gap-3">{error ? <><Text className="text-ink">{error}</Text><Button title="Opnieuw proberen" onPress={() => void refresh()} /></> : <ActivityIndicator color="#127A79" />}</View> : <View className="gap-5">
        <Text className="font-bold text-[28px] text-ink">{data.balance} credits beschikbaar</Text>
        <View className="gap-2 rounded-2xl bg-mint-50 p-5">
          <Text className="font-bold text-[17px] text-ink">Van Basic</Text>
          <Text className="text-[16px] text-ink">{data.membership} van maximaal {data.membershipCap} credits</Text>
          <Text className="mt-3 font-bold text-[17px] text-ink">Zelf bijgekocht</Text>
          <Text className="text-[16px] text-ink">{data.purchased} credits</Text>
          {data.other > 0 && <Text className="text-[14px] text-ink">Overige credits: {data.other}</Text>}
          {data.nextExpiry && <Text className="text-[14px] text-ink-70">Eerstvolgende vervaldatum: {date(data.nextExpiry)}</Text>}
          {data.nextRenewal && <Text className="text-[14px] text-ink-70">Volgende {data.monthlyCredits} credits op {date(data.nextRenewal)} bij een geslaagde verlenging.</Text>}
        </View>
        {data.expiring.length > 0 && <View className="gap-2 rounded-xl border border-ink-8 p-4"><Text className="font-bold text-ink">Je credits verlopen binnenkort</Text>{data.expiring.map((expiry, index) => <Text key={index} className={expiry.urgent ? 'font-semi text-danger' : 'text-ink-70'}>{expiry.credits} credits verlopen op {date(expiry.date)}.</Text>)}</View>}
        {data.endsAt && <View className="gap-1"><Text className="font-semi text-ink">Je Basic-abonnement eindigt op {date(data.endsAt)}.</Text><Text className="text-ink-70">Resterende membership-credits vervallen daarna.</Text></View>}
        <TemporaryCreditButton disabled={busy} onAdded={() => refresh()} />
        {buying && <View className="gap-3">
          {!data.hasBasic && <Text className="text-ink-70">Je hebt een actief Basic-abonnement nodig om credits bij te kopen. Je bestaande aankopen en ontgrendelde items blijven beschikbaar.</Text>}
          {!data.checkoutAvailable && <Text className="text-ink-70">Credits bijkopen is nog niet beschikbaar.</Text>}
          {data.bundles.length === 0 && <Text className="text-ink-70">Er zijn op dit moment geen creditbundels beschikbaar.</Text>}
          {data.bundles.map(bundle => <View key={bundle.id} className="gap-2 rounded-2xl border border-ink-8 bg-white p-5">
            {!!bundle.label && <Text className="text-[13px] text-teal-700">{bundle.label}</Text>}
            <Text className="font-bold text-[20px] text-ink">{bundle.credits} credits</Text>
            <Text className="text-[18px] text-ink">{new Intl.NumberFormat('nl-NL', { style: 'currency', currency: bundle.currency }).format(bundle.price_cents / 100)}</Text>
            <Text className="text-ink-70">{data.validityMonths} maanden geldig</Text>
            <Button title="Kopen" disabled={busy || !data.hasBasic || !data.checkoutAvailable} onPress={() => void purchase(bundle)} />
          </View>)}
        </View>}
        {!!orderId && <Button title="Betaling controleren" variant="ghost" onPress={() => void checkOrder()} />}
        {!!message && <Text accessibilityRole="alert" className="text-ink-70">{message}</Text>}
        {data.hasBasic && !data.endsAt && <View className="gap-2">
          {confirmCancel ? <><Text className="text-ink">Basic opzeggen? Je behoudt toegang tot het einde van je betaalde periode. Daarna vervallen je membership-credits. Je aankopen en ontgrendelde items blijven behouden.</Text><Button title="Opzegging bevestigen" disabled={busy} onPress={() => void cancelBasic()} /><Button title="Annuleren" variant="text" disabled={busy} onPress={() => setConfirmCancel(false)} /></> : <Button title="Basic opzeggen" variant="text" onPress={() => setConfirmCancel(true)} />}
        </View>}
        <Text className="font-bold text-[20px] text-ink">Creditgeschiedenis</Text>
        {data.history.length === 0 && <Text className="text-ink-50">Nog geen credittransacties.</Text>}
        {data.history.map(row => <View key={row.id} className="flex-row justify-between gap-3 border-b border-ink-8 pb-3"><View className="flex-1"><Text className="font-semi text-ink">{row.description}</Text><Text className="text-[12px] text-ink-50">{date(row.created_at)} · {types[row.type] ?? row.type}</Text></View><Text className="font-bold text-ink">{row.amount > 0 ? '+' : ''}{row.amount}</Text></View>)}
      </View>}
    </ScrollView>
  </SafeAreaView></View>;
}

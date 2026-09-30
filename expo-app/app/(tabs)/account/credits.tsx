import { TemporaryCreditButton } from '@/components/credits/TemporaryCreditButton';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Platform, ScrollView, Pressable, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { SafeAreaView } from 'react-native-safe-area-context';
import { libraryRequest, useLibraryResource } from '@/hooks/useLibraryResource';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { libraryPath } from '@/lib/library';
import { creditDate as date } from '@/lib/credits';

import { type CreditSummary, type CreditBundle as Bundle } from '@/lib/credits';
// A stable request key survives retry of an uncertain checkout response.
const requestKey = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.floor(Math.random() * 16); return (c === 'x' ? r : (r & 3) | 8).toString(16); });

export default function CreditsScreen() {
  const params = useLocalSearchParams<{ itemId?: string; format?: string; buy?: string; orderId?: string }>();
  const padBottom = useTabBarPadding();
  const { data, error, refresh } = useLibraryResource<CreditSummary>('/credits');
  const [buying, setBuying] = useState(params.buy === '1');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
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

  return <View className="flex-1"><SafeAreaView edges={['top']} style={{ flex: 1 }}>
    <SubHeader title="Credits" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/account')} />
    <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: padBottom }}>
      {!data ? <View className="gap-3">{error ? <><Text className="text-ink">{error}</Text><Button title="Opnieuw proberen" onPress={() => void refresh()} /></> : <ActivityIndicator color="#127A79" />}</View> : <View className="gap-5">
        <View className="gap-1">
          <Text accessibilityLiveRegion="polite" className="font-bold text-[36px] text-ink">{data.balance} credits</Text>
          <Text className="text-[16px] text-ink-70">Beschikbaar</Text>
          {data.hasBasic && (data.endsAt
            ? <Text className="mt-2 text-[14px] text-ink-70">Je Basic-abonnement loopt tot {date(data.endsAt)}</Text>
            : data.nextRenewal && <Text className="mt-2 text-[14px] text-ink-70">Je volgende {data.monthlyCredits} credits komen op {date(data.nextRenewal)}</Text>)}
        </View>
        {data.expiring.length > 0 && <View className="gap-1 rounded-xl bg-mint-50 p-4">
          {data.expiring.map((expiry, index) => <Text key={index} className="text-[14px] text-ink">{expiry.credits} {expiry.credits === 1 ? 'credit verloopt' : 'credits verlopen'} op {date(expiry.date)}</Text>)}
        </View>}
        <View className="gap-3 rounded-2xl border border-ink-8 bg-white p-5">
          <Text className="font-bold text-[19px] text-ink">Hoe credits werken</Text>
          <View className="gap-1"><Text className="font-semi text-[15px] text-ink">{data.monthlyCredits} credits per maand</Text><Text className="text-[14px] leading-5 text-ink-70">Met Basic krijg je na iedere succesvolle verlenging {data.monthlyCredits} nieuwe credits. Ongebruikte Basic-credits kun je opsparen tot maximaal {data.membershipCap} credits.</Text></View>
          <View className="gap-1"><Text className="font-semi text-[15px] text-ink">Ontgrendel wat bij jou past</Text><Text className="text-[14px] leading-5 text-ink-70">Gebruik je credits voor artikelen, video's en andere bibliotheekitems. Een eenmaal ontgrendeld item hoef je nooit meer opnieuw met credits te ontgrendelen.</Text></View>
          <View className="gap-1"><Text className="font-semi text-[15px] text-ink">Je bibliotheek wordt bewaard</Text><Text className="text-[14px] leading-5 text-ink-70">Ontgrendelde items blijven opgeslagen in je persoonlijke bibliotheek. Zolang Basic actief is kun je ze bekijken. Na beëindiging van Basic wordt de toegang gepauzeerd. Word je later opnieuw lid, dan krijg je direct weer toegang tot je eerder ontgrendelde items.</Text></View>
          <View className="gap-1"><Text className="font-semi text-[15px] text-ink">Extra credits</Text><Text className="text-[14px] leading-5 text-ink-70">Bijgekochte credits zijn {data.validityMonths} maanden geldig vanaf aankoopdatum.</Text></View>
        </View>
        {data.bundles.length > 0 && (data.checkoutAvailable
          ? <Button title="Credits bijkopen" onPress={() => setBuying(value => !value)} />
          : <TemporaryCreditButton disabled={busy} onAdded={() => refresh()} />)}
        {buying && data.checkoutAvailable && data.bundles.length > 0 && <View className="gap-3">
          {!data.hasBasic && <Text className="text-ink-70">Je hebt een actief Basic-abonnement nodig om credits bij te kopen.</Text>}
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
        <Pressable accessibilityRole="button" accessibilityLabel="Creditgeschiedenis" onPress={() => router.push('/(tabs)/account/credit-history')} className="flex-row items-center justify-between border-b border-ink-8 py-3">
          <Text className="font-semi text-[16px] text-ink">Creditgeschiedenis</Text><Text className="text-[20px] text-teal-700">→</Text>
        </Pressable>
      </View>}
    </ScrollView>
  </SafeAreaView></View>;
}

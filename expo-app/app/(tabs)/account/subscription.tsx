import { ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Check, ChevronRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { Card } from '@/components/ui/Card';
import { Chip } from '@/components/ui/Chip';
import { SubscriptionRenewal } from '@/components/subscription/SubscriptionRenewal';
import { useActiveSubscription, usePlan, usePlanBenefits } from '@/db/hooks';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { creditDate } from '@/lib/credits';
import { subscriptionActive, subscriptionPriceLabel } from '@/lib/subscription';

export default function SubscriptionScreen() {
  const padBottom = useTabBarPadding();
  const subscription = useActiveSubscription();
  const plan = usePlan(subscription?.planId ?? '');
  const benefits = usePlanBenefits(subscription?.planId ?? '');
  const active = !!subscription && subscriptionActive({
    status: subscription.status,
    started_at: subscription.startedAt,
    paid_through: subscription.paidThrough,
    ended_at: subscription.endedAt,
    cancelled_at: subscription.cancelledAt,
  });
  if (!active) return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title="Abonnement" onBack={() => router.back()} />
    <SubscriptionRenewal />
  </SafeAreaView>;

  const rows: [string, string][] = [];
  const since = (subscription.startedLabel as string) || (subscription.startedAt ? `Sinds ${creditDate(subscription.startedAt as string)}` : '');
  if (since) rows.push(['Lid', since.replace(/^Sinds /, 'sinds ')]);
  if (subscription.cancelRequestedAt) rows.push(['Status', 'Opzegging aangevraagd']);
  if (subscription.paidThrough) rows.push([subscription.cancelRequestedAt ? 'Toegang tot' : 'Betaald tot', creditDate(subscription.paidThrough as string)]);
  else if (subscription.renewsLabel) rows.push(['Verlenging', subscription.renewsLabel as string]);

  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title="Abonnement" onBack={() => router.back()} />
    <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: padBottom + 20, gap: 16 }}>
      <View className="rounded-card bg-teal-700 p-5">
        <Chip label="Actief" variant="success" />
        <Text className="mt-3 font-bold text-[22px] leading-7 text-white">{(plan.name as string) || 'EquiApp abonnement'}</Text>
        <Text className="mt-1 font-sans text-[15px] text-white/80">
          {subscriptionPriceLabel(subscription.priceCents as number, subscription.interval as string, plan.priceSuffix as string)}
        </Text>
        {rows.length > 0 && <View className="mt-4 gap-2 border-t border-white/15 pt-4">
          {rows.map(([label, value]) => <View key={label} className="flex-row justify-between gap-4">
            <Text className="font-sans text-[14px] text-white/80">{label}</Text>
            <Text className="shrink text-right font-semi text-[14px] text-white">{value}</Text>
          </View>)}
        </View>}
      </View>

      {benefits.length > 0 && <Card flat>
        <Text className="font-bold text-[16px] text-ink">Wat je abonnement bevat</Text>
        {benefits.map(benefit => <View key={benefit.id as string} className="flex-row items-start gap-2.5">
          <Check size={18} color="#127A79" style={{ marginTop: 2 }} />
          <Text className="flex-1 font-sans text-[15px] leading-[22px] text-ink">{benefit.label as string}</Text>
        </View>)}
      </Card>}

      <Card flat onPress={() => router.push('/(tabs)/account/credits')}>
        <View className="flex-row items-center justify-between gap-3">
          <View className="flex-1">
            <Text className="font-bold text-[16px] text-ink">Credits</Text>
            <Text className="font-sans text-[14px] text-ink-70">Saldo, bijkopen en creditgeschiedenis</Text>
          </View>
          <ChevronRight size={18} color="#127A79" />
        </View>
      </Card>
    </ScrollView>
  </SafeAreaView>;
}

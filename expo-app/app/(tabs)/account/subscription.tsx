import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Check, ArrowRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useActiveSubscription,
  usePayments,
  usePlan,
  usePlanBenefits,
  usePlans,
} from '@/db/hooks';

export default function SubscriptionScreen() {
  const padBottom = useTabBarPadding();
  const subscription = useActiveSubscription();
  const plan = usePlan(subscription?.planId ?? 'plan-plus');
  const benefits = usePlanBenefits(plan.id);
  const payments = usePayments(subscription?.id ?? '');

  const plans = usePlans();
  const upgradePlan = plans.find((p: any) => p.isRecommended);
  const upgradeBenefits = usePlanBenefits(upgradePlan?.id ?? '');

  if (!subscription) {
    return (
      <View className="flex-1 bg-canvas">
        <SafeAreaView edges={['top']} style={{ flex: 1 }}>
          <SubHeader title="Abonnement" onBack={() => router.back()} />
        </SafeAreaView>
      </View>
    );
  }

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader title="Abonnement" onBack={() => router.back()} />
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: padBottom }}>
          <View className="mb-3.5 rounded-card bg-teal-700 p-[18px]">
            <View className="flex-row justify-between">
              <View className="self-start rounded-pill bg-mint-500 px-3 py-1">
                <Text className="font-semi text-white text-[12px]">{plan.label as string} · Actief</Text>
              </View>
              <Text className="text-white/70 text-[12px]">{subscription.startedLabel as string}</Text>
            </View>
            <View className="mt-3 flex-row items-baseline">
              <Text className="font-bold text-white" style={{ fontSize: 32 }}>
                € {((subscription.priceCents as number) / 100).toFixed(0)}
              </Text>
              <Text className="font-medium text-white/70 ml-1.5" style={{ fontSize: 14 }}>
                {plan.priceSuffix as string}
              </Text>
            </View>
            <Text className="text-white/75 text-[13px] mt-1">
              Verlengt automatisch op {monthDayLabel(subscription.renewsAt as string)}
            </Text>

            <View className="mt-4 pt-4 gap-2" style={{ borderTopWidth: 1, borderTopColor: 'rgba(255,255,255,0.12)' }}>
              {benefits.map((b: any) => (
                <View key={b.id} className="flex-row items-center gap-2.5">
                  <Check size={16} color="#5FD7CB" strokeWidth={2.5} />
                  <Text className="text-white text-[13px]">{b.label}</Text>
                </View>
              ))}
            </View>
          </View>

          {upgradePlan && (
            <>
              <SectionTitle>Upgrade pad</SectionTitle>
              <View className="rounded-card border border-mint-300 bg-white p-[18px] mb-4">
                <View className="flex-row items-start justify-between">
                  <View className="flex-1">
                    <View className="self-start"><Chip label={upgradePlan.label as string} /></View>
                    <Text className="font-bold text-ink mt-2" style={{ fontSize: 18 }}>{upgradePlan.name as string}</Text>
                    <Text className="text-[12px] text-ink-50 mt-0.5">{upgradePlan.description as string}</Text>
                    {upgradeBenefits.length > 0 && (
                      <View className="mt-2 gap-1">
                        {upgradeBenefits.map((b: any) => (
                          <Text key={b.id} className="text-[12px] text-ink-70">• {b.label}</Text>
                        ))}
                      </View>
                    )}
                  </View>
                  <View className="items-end">
                    <Text className="font-bold text-ink text-[15px]">
                      € {((upgradePlan.priceCents as number) / 100).toLocaleString('nl-NL')}
                    </Text>
                    <Text className="text-[11px] text-ink-50">{upgradePlan.priceSuffix as string}</Text>
                  </View>
                </View>
                <View className="mt-3">
                  <Button title="Bekijk opleiding" trailing={<ArrowRight size={18} color="#fff" />} />
                </View>
              </View>
            </>
          )}

          <SectionTitle>Betalingen</SectionTitle>
          <View className="px-1">
            {payments.map((p: any) => (
              <View key={p.id} className="flex-row justify-between border-b border-ink-8 py-3">
                <Text className="text-ink text-[14px]">{p.dateLabel}</Text>
                <Text className="font-semi text-ink text-[14px]">{p.amountLabel}</Text>
              </View>
            ))}
          </View>

          <View className="pt-5">
            <Button title="Abonnement opzeggen" variant="ghost" textClassName="text-danger" />
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

function monthDayLabel(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  const months = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
  return `${d.getDate()} ${months[d.getMonth()]}`;
}

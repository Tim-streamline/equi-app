import { ActivityIndicator, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { creditDate, type CreditSummary } from '@/lib/credits';

const labels: Record<string, string> = { membership: 'Maandcredits ontvangen', purchased: 'Credits bijgekocht', spent: 'Credits gebruikt', expired: 'Credits verlopen', refund: 'Terugboeking', adjustment: 'Correctie', promotional: 'Actiecredits ontvangen' };
export default function CreditHistoryScreen() {
  const padBottom = useTabBarPadding();
  const { data, error, refresh } = useLibraryResource<CreditSummary>('/credits');
  return <View className="flex-1"><SafeAreaView edges={['top']} style={{ flex: 1 }}>
    <SubHeader title="Creditgeschiedenis" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/account/credits')} />
    <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: padBottom }}>
      {!data ? error ? <View className="gap-3"><Text className="text-ink">{error}</Text><Button title="Opnieuw proberen" onPress={() => void refresh()} /></View> : <ActivityIndicator color="#127A79" /> : <View className="gap-4">
        {data.history.length === 0 && <Text className="text-ink-50">Nog geen credittransacties.</Text>}
        {data.history.map(row => <View key={row.id} className="flex-row justify-between gap-3 border-b border-ink-8 pb-3">
          <View className="flex-1"><Text className="font-semi text-ink">{labels[row.type] ?? 'Creditwijziging'}</Text>
            {row.type === 'spent' && <Text className="text-[14px] text-ink-70">{row.description}</Text>}
            <Text className="text-[12px] text-ink-50">{creditDate(row.created_at)}</Text></View>
          <Text className="font-bold text-ink">{row.amount > 0 ? '+' : ''}{row.amount}</Text>
        </View>)}
      </View>}
    </ScrollView>
  </SafeAreaView></View>;
}

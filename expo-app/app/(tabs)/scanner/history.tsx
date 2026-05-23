import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ChevronRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { ScoreRing } from '@/components/ui/ScoreRing';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useScanResults } from '@/db/hooks';

export default function ScannerHistoryScreen() {
  const padBottom = useTabBarPadding();
  const scans = useScanResults();
  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader title="Eerdere scans" onBack={() => router.back()} />
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: padBottom, gap: 10 }}>
          {scans.map((s: any) => {
            const score = (s.score as number) ?? 0;
            const cls: 'success' | 'warn' | 'danger' = score >= 75 ? 'success' : score >= 50 ? 'warn' : 'danger';
            return (
              <Pressable
                key={s.id}
                onPress={() => router.push({ pathname: '/(tabs)/scanner/result', params: { id: s.id } } as any)}
                className="flex-row items-center gap-3 rounded-2xl border border-ink-8 bg-white p-3.5"
              >
                <ScoreRing score={score} size={52} stroke={4} />
                <View className="flex-1">
                  <Text className="font-semi text-ink text-[14px]" numberOfLines={2}>{s.productName}</Text>
                  <View className="mt-1 flex-row items-center gap-2">
                    <Text className="text-[12px] text-ink-50">{s.whenLabel}</Text>
                    <Chip label={s.rating} variant={cls} />
                  </View>
                </View>
                <ChevronRight size={18} color="rgba(27,42,42,0.5)" />
              </Pressable>
            );
          })}
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

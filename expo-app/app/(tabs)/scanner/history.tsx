import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ChevronRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { ScoreRing } from '@/components/ui/ScoreRing';
import { Chip } from '@/components/ui/Chip';
import { SCAN_HISTORY } from '@/data/mock';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';

export default function ScannerHistoryScreen() {
  const padBottom = useTabBarPadding();
  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader title="Eerdere scans" onBack={() => router.back()} />
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: padBottom, gap: 10 }}>
          {SCAN_HISTORY.map((s) => {
            const cls: 'success' | 'warn' | 'danger' =
              s.score >= 75 ? 'success' : s.score >= 50 ? 'warn' : 'danger';
            return (
              <Pressable
                key={s.id}
                onPress={() => router.push('/(tabs)/scanner/result')}
                className="flex-row items-center gap-3 rounded-2xl border border-ink-8 bg-white p-3.5"
              >
                <ScoreRing score={s.score} size={52} stroke={4} />
                <View className="flex-1">
                  <Text className="font-semi text-ink text-[14px]" numberOfLines={2}>{s.t}</Text>
                  <View className="mt-1 flex-row items-center gap-2">
                    <Text className="text-[12px] text-ink-50">{s.when}</Text>
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

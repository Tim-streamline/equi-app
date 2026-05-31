import { View, Text, ScrollView, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { MoreHorizontal } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { IconButton } from '@/components/ui/IconButton';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useFocusForHorse, useHorse, useHorseStats, useTimeline } from '@/db/hooks';

export default function HorseProfileScreen() {
  const padBottom = useTabBarPadding();
  const horse = useHorse();
  const stats = useHorseStats();
  const focus = useFocusForHorse();
  const timeline = useTimeline();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          onBack={() => router.back()}
          right={
            <IconButton>
              <MoreHorizontal size={20} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="mx-4 mb-4 overflow-hidden rounded-2xl">
            <LinearGradient
              colors={['#30C7BA', '#0D5C5B']}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={{ padding: 24 }}
            >
              <View className="flex-row items-start justify-between">
                <View>
                  <Text className="font-bold text-white" style={{ fontSize: 32, lineHeight: 36 }}>
                    {horse.name as string}
                  </Text>
                  <Text className="mt-1 text-white/80 text-[13px]">
                    {horse.breed as string} · {horse.age as number} jr · {horse.sex as string}
                  </Text>
                </View>
                <Image
                  source={require('@/assets/images/logo-horse-white.png')}
                  style={{ width: 60, height: 60, opacity: 0.5, resizeMode: 'contain' }}
                />
              </View>
              <View className="mt-4 flex-row flex-wrap gap-1.5">
                {focus.map((f) => (
                  <View key={f.id} className="rounded-pill bg-white/20 px-3 py-1">
                    <Text className="font-semi text-white text-[12px]">
                      {(f.extraLabel as string) || (f.topic.title as string)}
                    </Text>
                  </View>
                ))}
              </View>
            </LinearGradient>
          </View>

          <View className="mb-4 flex-row gap-2.5 px-4">
            {stats.map((s: any) => (
              <View key={s.id} className="flex-1 rounded-card border border-ink-8 bg-white p-3">
                <Text className="font-semi uppercase text-ink-50" style={{ fontSize: 10, letterSpacing: 1.2 }}>
                  {s.label}
                </Text>
                <Text className="mt-1 font-bold text-ink" style={{ fontSize: 18 }}>{s.valueLabel}</Text>
                <Text className="mt-0.5 text-[11px] text-ink-50">{s.trend}</Text>
              </View>
            ))}
          </View>

          <SectionTitle action="Volledig dagboek" onAction={() => router.push('/(tabs)/(pager)/protocol')}>
            Tijdlijn
          </SectionTitle>
          <View className="px-7 pb-4">
            {timeline.map((step: any, i: number) => (
              <View key={step.id} className="flex-row gap-3.5 pb-4">
                <View className="items-center" style={{ width: 14 }}>
                  <View
                    className={`mt-1 h-3 w-3 rounded-full ${step.isNow ? 'bg-mint-500' : 'bg-ink-15'}`}
                  />
                  {i < timeline.length - 1 && (
                    <View className="flex-1 w-px bg-ink-8" style={{ minHeight: 30 }} />
                  )}
                </View>
                <View className="flex-1">
                  <Text className="font-semi uppercase text-ink-50" style={{ fontSize: 10, letterSpacing: 1.2 }}>
                    {step.whenLabel}
                  </Text>
                  <Text className="mt-1 text-[14px] text-ink">{step.message}</Text>
                </View>
              </View>
            ))}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

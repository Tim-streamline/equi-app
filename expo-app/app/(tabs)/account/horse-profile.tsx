import { View, Text, ScrollView, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { MoreHorizontal } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { IconButton } from '@/components/ui/IconButton';
import { HORSE } from '@/data/mock';

const STATS = [
  { l: 'Gewicht', v: '540 kg', t: 'stabiel' },
  { l: 'Energie', v: '7 / 10', t: '↑ +1 deze week' },
  { l: 'Mest-score', v: 'B+', t: 'stabiel' },
];

const TIMELINE = [
  { when: 'vandaag', what: 'Brandnetel toegevoegd aan protocol (mei-seizoenstip)', now: true },
  { when: '3 dagen geleden', what: 'Foto van mest geüpload — Score B+' },
  { when: '2 weken geleden', what: 'Intake met Shelley · jeukklachten + spijsvertering' },
  { when: '3 weken geleden', what: 'Nova toegevoegd aan EquiNova' },
];

export default function HorseProfileScreen() {
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
        <ScrollView contentContainerStyle={{ paddingBottom: 120 }}>
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
                    {HORSE.name}
                  </Text>
                  <Text className="mt-1 text-white/80 text-[13px]">
                    {HORSE.breed} · {HORSE.age} jr · {HORSE.sex}
                  </Text>
                </View>
                <Image
                  source={require('@/assets/images/logo-horse-white.png')}
                  style={{ width: 60, height: 60, opacity: 0.5, resizeMode: 'contain' }}
                />
              </View>
              <View className="mt-4 flex-row flex-wrap gap-1.5">
                {HORSE.focus.map((f) => (
                  <View key={f} className="rounded-pill bg-white/20 px-3 py-1">
                    <Text className="font-semi text-white text-[12px]">{f}</Text>
                  </View>
                ))}
              </View>
            </LinearGradient>
          </View>

          <View className="mb-4 flex-row gap-2.5 px-4">
            {STATS.map((s) => (
              <View key={s.l} className="flex-1 rounded-card border border-ink-8 bg-white p-3">
                <Text className="font-semi uppercase text-ink-50" style={{ fontSize: 10, letterSpacing: 1.2 }}>
                  {s.l}
                </Text>
                <Text className="mt-1 font-bold text-ink" style={{ fontSize: 18 }}>{s.v}</Text>
                <Text className="mt-0.5 text-[11px] text-ink-50">{s.t}</Text>
              </View>
            ))}
          </View>

          <SectionTitle action="Volledig dagboek" onAction={() => router.push('/(tabs)/protocol')}>
            Tijdlijn
          </SectionTitle>
          <View className="px-7 pb-4">
            {TIMELINE.map((step, i) => (
              <View key={i} className="flex-row gap-3.5 pb-4">
                <View className="items-center" style={{ width: 14 }}>
                  <View
                    className={`mt-1 h-3 w-3 rounded-full ${step.now ? 'bg-mint-500' : 'bg-ink-15'}`}
                  />
                  {i < TIMELINE.length - 1 && (
                    <View className="flex-1 w-px bg-ink-8" style={{ minHeight: 30 }} />
                  )}
                </View>
                <View className="flex-1">
                  <Text className="font-semi uppercase text-ink-50" style={{ fontSize: 10, letterSpacing: 1.2 }}>
                    {step.when}
                  </Text>
                  <Text className="mt-1 text-[14px] text-ink">{step.what}</Text>
                </View>
              </View>
            ))}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

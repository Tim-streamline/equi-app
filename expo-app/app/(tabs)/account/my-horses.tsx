import { View, Text, ScrollView, Pressable, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { Plus } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Card } from '@/components/ui/Card';
import { Chip } from '@/components/ui/Chip';
import { Bigchip } from '@/components/ui/Bigchip';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useFocusForHorse, useHorseShares, useHorsesByOwner } from '@/db/hooks';

export default function MyHorsesScreen() {
  const padBottom = useTabBarPadding();
  const horses = useHorsesByOwner();
  const active = horses.filter((h) => h.status === 'active');
  const archived = horses.filter((h) => h.status === 'archived');
  const shares = useHorseShares();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Mijn paarden"
          onBack={() => router.back()}
          right={
            <IconButton>
              <Plus size={20} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: padBottom, gap: 12 }}>
          {active.map((horse) => (
            <ActiveHorseCard key={horse.id} horse={horse} />
          ))}

          {archived.map((h) => (
            <View key={h.id} className="rounded-card border border-ink-8 bg-white p-4 opacity-70">
              <View className="flex-row items-start gap-3">
                <View className="items-center justify-center rounded-2xl bg-ink-8" style={{ width: 60, height: 60 }}>
                  <Image
                    source={require('@/assets/images/logo-horse-mark.png')}
                    style={{ width: 36, height: 36, opacity: 0.4, resizeMode: 'contain' }}
                  />
                </View>
                <View className="flex-1">
                  <Text className="font-bold text-ink" style={{ fontSize: 18 }}>{h.name as string}</Text>
                  <Text className="mt-0.5 text-[12px] text-ink-50">
                    {h.breed as string} · {h.age as number} jaar · {h.sex as string}
                  </Text>
                  {h.archivedNote ? (
                    <Text className="mt-1.5 font-italic text-[11px] text-ink-50">
                      {h.archivedNote as string}
                    </Text>
                  ) : null}
                </View>
              </View>
            </View>
          ))}

          <Bigchip title="Voeg paard toe" dashed icon={<Plus size={20} color="#0D5C5B" />} />

          {shares.length > 0 && (
            <View>
              <SectionTitle>Gedeeld met</SectionTitle>
              <View className="px-1">
                {shares.map((s: any) => {
                  const isTherapist = !!s.therapist;
                  const name = isTherapist
                    ? `${s.therapist.name} · ${s.therapist.title}`
                    : `${s.user?.name ?? ''} (medeverzorger)`;
                  const initial = isTherapist
                    ? (s.therapist.avatarInitial ?? 'S')
                    : (s.user?.avatarInitial ?? '?');
                  const sub = isTherapist
                    ? `Volledige toegang · therapeut`
                    : `Alleen-lezen · sinds ${monthLabel(s.since)}`;
                  const bg = isTherapist ? '#0D5C5B' : '#5FD7CB';
                  return <ShareRow key={s.id} name={name} sub={sub} bg={bg} initial={initial} />;
                })}
              </View>
            </View>
          )}
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

function ActiveHorseCard({ horse }: { horse: any }) {
  const focus = useFocusForHorse(horse.id);
  return (
    <Card onPress={() => router.push('/(tabs)/account/horse-profile')}>
      <View className="flex-row items-start gap-3">
        <LinearGradient
          colors={['#30C7BA', '#0D5C5B']}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={{ width: 60, height: 60, borderRadius: 16, alignItems: 'center', justifyContent: 'center' }}
        >
          <Image
            source={require('@/assets/images/logo-horse-white.png')}
            style={{ width: 36, height: 36, resizeMode: 'contain' }}
          />
        </LinearGradient>
        <View className="flex-1">
          <View className="flex-row items-center gap-2">
            <Text className="font-bold text-ink" style={{ fontSize: 19 }}>{horse.name}</Text>
            <Chip label="Actief" variant="success" />
          </View>
          <Text className="mt-0.5 text-[12px] text-ink-50">
            {horse.breed} · {horse.age} jaar · {horse.sex} · {horse.weightKg} kg
          </Text>
          <View className="mt-2 flex-row gap-1.5 flex-wrap">
            {focus.map((f) => (
              <Chip key={f.id} label={(f.topic.title as string)?.split('klachten')[0] || (f.topic.title as string)} variant="outline" />
            ))}
          </View>
        </View>
      </View>
    </Card>
  );
}

function ShareRow({ name, sub, bg, initial }: { name: string; sub: string; bg: string; initial: string }) {
  return (
    <View className="flex-row items-center justify-between border-b border-ink-8 py-3">
      <View className="flex-row items-center gap-3 flex-1">
        <View className="h-8 w-8 items-center justify-center rounded-full" style={{ backgroundColor: bg }}>
          <Text className="font-bold text-white text-[12px]">{initial}</Text>
        </View>
        <View className="flex-1">
          <Text className="font-semi text-ink text-[14px]">{name}</Text>
          <Text className="text-[11px] text-ink-50">{sub}</Text>
        </View>
      </View>
      <Pressable hitSlop={8}>
        <Text className="font-semi text-mint-700 text-[13px]">Beheer</Text>
      </Pressable>
    </View>
  );
}

function monthLabel(iso?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  const months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
  return months[d.getMonth()];
}

import { View, Text, ScrollView, Pressable, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Bell, ScanLine, BookOpen, ChevronRight, Sparkles, Play, Check } from 'lucide-react-native';
import { AppHeader } from '@/components/ui/AppHeader';
import { Coach } from '@/components/ui/Coach';
import { Tile } from '@/components/ui/Tile';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Card } from '@/components/ui/Card';
import { Bigchip } from '@/components/ui/Bigchip';
import { ProgressRing } from '@/components/ui/ProgressRing';
import { IconButton } from '@/components/ui/IconButton';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { IntakeEntryCard } from '@/components/intake/IntakeEntryCard';
import {
  useActiveProtocolForHorse,
  useActiveSeasonalTip,
  useCurrentHorseId,
  useCurrentUser,
  useHorse,
  useLibraryFeatured,
  useStoreMutations,
  useTodayTasks,
  useValue,
} from '@/db/hooks';

export default function HomeScreen() {
  const user = useCurrentUser();
  const horse = useHorse();
  const seasonal = useActiveSeasonalTip();
  const featured = useLibraryFeatured();
  const protocol = useActiveProtocolForHorse();
  const horseId = useCurrentHorseId();
  const mutations = useStoreMutations();
  const now = new Date();
  const todayIso = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  const tasks = useTodayTasks(protocol?.id ?? '', todayIso);
  const done = tasks.filter((t) => t.done).length;
  const padBottom = useTabBarPadding();
  // Open the protocol tab on its calendar sub-tab; the unique token forces the
  // sub-tab effect to re-fire on every tap, and the calendar remounts on today.
  const openCalendar = () =>
    router.push({
      pathname: '/(tabs)/(pager)/protocol',
      params: { tab: 'kalender', t: String(Date.now()) },
    } as any);

  return (
    <View className="flex-1">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <AppHeader
            title={(user.name as string)?.split(' ')[0] ?? ''}
            right={
              <IconButton>
                <Bell size={20} color="#1B2A2A" />
              </IconButton>
            }
          />

          {seasonal && (
            <Coach tag={`Seizoenstip · ${seasonal.month}`}>{seasonal.body}</Coach>
          )}

          <IntakeEntryCard />

          <View className="mb-3.5 flex-row gap-2.5 px-4">
            <Tile
              label="Scanner"
              sub="Voer beoordelen"
              icon={<ScanLine size={22} color="#127A79" />}
              onPress={() => router.push('/(tabs)/scanner')}
            />
            <Tile
              label="Bibliotheek"
              sub="3 nieuwe items"
              icon={<BookOpen size={22} color="#127A79" />}
              onPress={() => router.push('/(tabs)/(pager)/library')}
            />
          </View>

          <SectionTitle action="Open kalender" onAction={openCalendar}>
            Vandaag · {horse.name}
          </SectionTitle>
          <View className="px-4 mb-4">
            <Card onPress={openCalendar}>
              <View className="flex-row items-center justify-between">
                <Text className="font-bold text-ink text-[15px]">Dagelijks protocol</Text>
                <View className="flex-row items-center gap-2">
                  <Text className="text-[12px] text-ink-50">
                    {done} van {tasks.length} gedaan
                  </Text>
                  <ProgressRing value={tasks.length ? (done / tasks.length) * 100 : 0} size={28} stroke={3} />
                </View>
              </View>
              <View className="gap-2 mt-2">
                {tasks.map((p) => (
                  <Pressable
                    key={p.id}
                    onPress={() => mutations.toggleTaskCompletion(p.id, todayIso, horseId)}
                    className="flex-row items-center gap-3 py-1"
                    hitSlop={6}
                  >
                    <View
                      className={`h-5 w-5 items-center justify-center rounded-full border-2 ${
                        p.done ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
                      }`}
                    >
                      {p.done && <Check size={12} color="#fff" strokeWidth={3} />}
                    </View>
                    <View className="flex-1">
                      <Text
                        className={`text-[14px] ${p.done ? 'text-ink-50 line-through' : 'text-ink'}`}
                      >
                        {p.label}
                      </Text>
                      <Text className="mt-0.5 text-[11px] text-ink-50">{p.meta}</Text>
                    </View>
                  </Pressable>
                ))}
              </View>
            </Card>
          </View>

          {featured && (
            <>
              <SectionTitle action="Alles" onAction={() => router.push('/(tabs)/(pager)/library')}>
                Voor jou geselecteerd
              </SectionTitle>
              <View className="px-4 mb-4">
                <Card onPress={() => router.push({ pathname: '/(tabs)/library/video/[id]', params: { id: featured.id } } as any)}>
                  <View
                    className="overflow-hidden rounded-2xl"
                    style={{ height: 140, backgroundColor: '#0D5C5B', position: 'relative' }}
                  >
                    <Image
                      source={require('@/assets/images/logo-horse-white.png')}
                      style={{ position: 'absolute', top: '50%', left: '50%', width: 90, height: 90, marginLeft: -45, marginTop: -45, opacity: 0.35, resizeMode: 'contain' }}
                    />
                    <View
                      style={{
                        position: 'absolute',
                        top: '50%',
                        left: '50%',
                        width: 56,
                        height: 56,
                        marginLeft: -28,
                        marginTop: -28,
                        borderRadius: 28,
                        backgroundColor: 'rgba(255,255,255,0.95)',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <Play size={22} color="#0D5C5B" fill="#0D5C5B" />
                    </View>
                    <Text
                      className="font-semi text-white"
                      style={{ position: 'absolute', bottom: 10, right: 10, fontSize: 12 }}
                    >
                      {featured.durationLabel as string}
                    </Text>
                  </View>
                  <Text className="mt-2 font-bold text-ink" style={{ fontSize: 16 }}>
                    {featured.title as string}
                  </Text>
                  <Text className="mt-1 text-[13px] text-ink-50">{featured.description as string}</Text>
                </Card>
              </View>
            </>
          )}

          <SectionTitle>Vraag Nova</SectionTitle>
          <View className="px-4 pb-5">
            <Bigchip
              title="Stel een vraag aan Nova"
              description={(useValue('novaSubtitle') as string) || ''}
              icon={<Sparkles size={20} color="#127A79" />}
              onPress={() => router.push('/nova-chat')}
              trailing={<ChevronRight size={18} color="rgba(27,42,42,0.5)" />}
            />
          </View>

          <Pressable
            onPress={() => router.push('/(tabs)/account/horse-profile' as any)}
            className="mx-4 -mt-2 mb-4 self-start"
          >
            <Text className="font-semi text-mint-700 text-[13px]">Bekijk {horse.name}&apos;s paardprofiel →</Text>
          </Pressable>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

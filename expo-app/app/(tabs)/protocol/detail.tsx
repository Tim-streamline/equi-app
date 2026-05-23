import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { MoreHorizontal, Check, ChevronRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Coach } from '@/components/ui/Coach';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useActiveProtocolForHorse,
  useCurrentHorseId,
  useScreenCopy,
  useStoreMutations,
  useTodayTasks,
  useValue,
} from '@/db/hooks';

const TODAY_ISO = '2026-05-16';

export default function ProtocolDetailScreen() {
  const padBottom = useTabBarPadding();
  const protocol = useActiveProtocolForHorse();
  const horseId = useCurrentHorseId();
  const items = useTodayTasks(protocol?.id ?? '', TODAY_ISO);
  const mutations = useStoreMutations();
  const headerLabel = useValue('detailTodayLabel') as string;
  const copy = useScreenCopy('protocolDetail');

  const morning = items.filter((p) => p.meta === 'Ochtendvoer');
  const obs = items.filter((p) => p.meta !== 'Ochtendvoer');
  const done = items.filter((p) => p.done).length;

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title={headerLabel}
          onBack={() => router.back()}
          right={
            <IconButton>
              <MoreHorizontal size={20} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          {copy.coach ? <Coach tag="Toelichting">{copy.coach}</Coach> : null}

          <SectionTitle>Ochtend</SectionTitle>
          <View className="px-4 mb-4">
            <Card flat>
              {morning.map((p, idx) => (
                <View
                  key={p.id}
                  className="flex-row items-center gap-3"
                  style={{ paddingVertical: idx === 0 ? 0 : 8 }}
                >
                  <Pressable
                    onPress={() => mutations.toggleTaskCompletion(p.id, TODAY_ISO, horseId)}
                    className={`h-5 w-5 items-center justify-center rounded-full border-2 ${
                      p.done ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
                    }`}
                    hitSlop={10}
                  >
                    {p.done && <Check size={12} color="#fff" strokeWidth={3} />}
                  </Pressable>
                  <Text
                    className={`flex-1 text-[15px] ${p.done ? 'text-ink-50 line-through' : 'text-ink'}`}
                  >
                    {p.label}
                  </Text>
                  <ChevronRight size={16} color="rgba(27,42,42,0.5)" />
                </View>
              ))}
            </Card>
          </View>

          <SectionTitle>Observaties</SectionTitle>
          <View className="px-4 mb-4">
            <Card flat>
              {obs.map((p) => (
                <View key={p.id} className="flex-row items-center gap-3 py-1">
                  <Pressable
                    onPress={() => mutations.toggleTaskCompletion(p.id, TODAY_ISO, horseId)}
                    className={`h-5 w-5 items-center justify-center rounded-full border-2 ${
                      p.done ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
                    }`}
                    hitSlop={10}
                  >
                    {p.done && <Check size={12} color="#fff" strokeWidth={3} />}
                  </Pressable>
                  <View className="flex-1">
                    <Text
                      className={`text-[15px] ${p.done ? 'text-ink-50 line-through' : 'text-ink'}`}
                    >
                      {p.label}
                    </Text>
                    <Text className="mt-0.5 text-[11px] text-ink-50">{p.meta}</Text>
                  </View>
                </View>
              ))}
            </Card>
          </View>

          <View className="px-4 pb-4">
            <Button
              title={`${done} van ${items.length} gedaan · Voeg observatie toe`}
              onPress={() => router.push('/(tabs)/protocol/log-entry')}
            />
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

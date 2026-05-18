import { useState } from 'react';
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
import { TODAY_PROTOCOL, type ProtocolItem } from '@/data/mock';

export default function ProtocolDetailScreen() {
  const [items, setItems] = useState<ProtocolItem[]>(TODAY_PROTOCOL);
  const toggle = (id: number) =>
    setItems(items.map((p) => (p.id === id ? { ...p, done: !p.done } : p)));
  const done = items.filter((p) => p.done).length;
  const morning = items.filter((p) => p.meta === 'Ochtendvoer');
  const obs = items.filter((p) => p.meta !== 'Ochtendvoer');

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Vandaag · 16 mei"
          onBack={() => router.back()}
          right={
            <IconButton>
              <MoreHorizontal size={20} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: 120 }}>
          <Coach tag="Toelichting">
            Vandaag iets minder lijnzaad, omdat de mest gisteren al iets losser was. Voeg{' '}
            <Text className="font-italic">één eetlepel</Text> brandnetel toe — vers is best.
          </Coach>

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
                    onPress={() => toggle(p.id)}
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
                    onPress={() => toggle(p.id)}
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

import { View, Text, ScrollView, Pressable, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import {
  ChevronRight, Plus, Bell, Download, Settings, Heart, MessageCircle,
} from 'lucide-react-native';
import { AppHeader } from '@/components/ui/AppHeader';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Card } from '@/components/ui/Card';
import { Bigchip } from '@/components/ui/Bigchip';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';

const ROWS = [
  { ic: Bell, t: 'Meldingen', s: '3 reminders aan' },
  { ic: MessageCircle, t: 'Community', s: 'Vraag & deel met paardenmensen', go: '/(tabs)/account/community' },
  { ic: Download, t: 'Exporteer mijn data', s: 'CSV of PDF dagboek' },
  { ic: Settings, t: 'Voorkeuren', s: 'Eenheden, taal' },
  { ic: Heart, t: 'Steun De Paardentherapeut', s: '' },
];

export default function AccountScreen() {
  const padBottom = useTabBarPadding();
  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <AppHeader title="Mijn account" avatar="M" />

          <View className="px-4 mb-4">
            <Card onPress={() => router.push('/(tabs)/account/my-horses')}>
              <View className="flex-row items-center gap-3">
                <LinearGradient
                  colors={['#30C7BA', '#0D5C5B']}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 1 }}
                  style={{ width: 48, height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center' }}
                >
                  <Text className="font-bold text-white" style={{ fontSize: 16 }}>M</Text>
                </LinearGradient>
                <View className="flex-1">
                  <Text className="font-bold text-ink" style={{ fontSize: 17 }}>Marit van der Berg</Text>
                  <Text className="text-[12px] text-ink-50">marit@voorbeeld.nl · Sinds april 2026</Text>
                </View>
                <ChevronRight size={18} color="rgba(27,42,42,0.5)" />
              </View>
            </Card>
          </View>

          <SectionTitle>Paarden</SectionTitle>
          <View className="px-4 gap-2.5 mb-4">
            <Card flat onPress={() => router.push('/(tabs)/account/horse-profile')}>
              <View className="flex-row items-center gap-3">
                <View className="h-11 w-11 items-center justify-center rounded-full bg-mint-100">
                  <Image
                    source={require('@/assets/images/logo-horse-mark.png')}
                    style={{ width: 28, height: 28, resizeMode: 'contain' }}
                  />
                </View>
                <View className="flex-1">
                  <Text className="font-bold text-ink text-[15px]">Nova</Text>
                  <Text className="text-[12px] text-ink-50">Friese kruising · 9 jr</Text>
                </View>
                <Chip label="Actief" variant="success" />
              </View>
            </Card>
            <Bigchip
              title="Voeg paard toe"
              dashed
              icon={<Plus size={20} color="#0D5C5B" />}
              onPress={() => router.push('/(tabs)/account/my-horses')}
            />
          </View>

          <SectionTitle>Abonnement</SectionTitle>
          <View className="px-4 mb-4">
            <Pressable
              onPress={() => router.push('/(tabs)/account/subscription')}
              className="rounded-card bg-teal-700 p-[18px]"
            >
              <View className="flex-row items-start justify-between">
                <View>
                  <View className="self-start rounded-pill bg-mint-500 px-3 py-1">
                    <Text className="font-semi text-white text-[12px]">Plus</Text>
                  </View>
                  <Text className="font-bold text-white mt-2.5" style={{ fontSize: 20 }}>
                    EquiNova Plus
                  </Text>
                  <Text className="text-white/70 mt-0.5 text-[12px]">
                    Verlengt 22 mei · € 12 / maand
                  </Text>
                </View>
                <ChevronRight size={18} color="rgba(255,255,255,0.7)" />
              </View>
            </Pressable>
          </View>

          <SectionTitle>Algemeen</SectionTitle>
          <View className="px-4 pb-4">
            {ROWS.map((r, i) => {
              const Icon = r.ic;
              return (
                <Pressable
                  key={i}
                  onPress={() => r.go && router.push(r.go as any)}
                  className="flex-row items-center justify-between border-b border-ink-8 py-3"
                >
                  <View className="flex-row items-center gap-3">
                    <Icon size={20} color="rgba(27,42,42,0.7)" />
                    <View>
                      <Text className="font-medium text-ink text-[14px]">{r.t}</Text>
                      {r.s ? <Text className="text-[11px] text-ink-50">{r.s}</Text> : null}
                    </View>
                  </View>
                  <ChevronRight size={16} color="rgba(27,42,42,0.5)" />
                </Pressable>
              );
            })}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

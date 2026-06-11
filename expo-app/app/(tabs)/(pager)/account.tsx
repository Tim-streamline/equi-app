import { useState } from 'react';
import { View, Text, ScrollView, Pressable, Image, Alert, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import Constants from 'expo-constants';
import {
  ChevronRight, Plus, Bell, Download, Settings, Heart, MessageCircle, LogOut,
} from 'lucide-react-native';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Card } from '@/components/ui/Card';
import { Bigchip } from '@/components/ui/Bigchip';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useAccountSettings,
  useActiveSubscription,
  useCurrentUser,
  useHorsesByOwner,
  usePlan,
} from '@/db/hooks';
import { useDb } from '@/db/provider';

const SETTINGS_ICONS: Record<string, any> = {
  bell: Bell,
  messageCircle: MessageCircle,
  download: Download,
  settings: Settings,
  heart: Heart,
};

function formatJoinedLabel(createdAt?: string) {
  if (!createdAt) return '';
  const d = new Date(createdAt);
  const months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
  return `Sinds ${months[d.getMonth()]} ${d.getFullYear()}`;
}

export default function AccountScreen() {
  const padBottom = useTabBarPadding();
  const user = useCurrentUser();
  const horses = useHorsesByOwner();
  const subscription = useActiveSubscription();
  const plan = usePlan(subscription?.planId ?? 'plan-plus');
  const settings = useAccountSettings();
  const { logout } = useDb();
  const [loggingOut, setLoggingOut] = useState(false);

  const active = horses.find((h) => h.status === 'active');

  const confirmLogout = () => {
    if (loggingOut) return;
    // Two-tap confirm — disconnectAndClear wipes the local SQLite cache,
    // so we never want this firing from a misclick.
    Alert.alert(
      'Uitloggen?',
      'Je antwoorden uit de protocol-intake en lokaal opgeslagen data worden gewist.',
      [
        { text: 'Annuleer', style: 'cancel' },
        {
          text: 'Uitloggen',
          style: 'destructive',
          onPress: async () => {
            setLoggingOut(true);
            try {
              await logout();
              router.replace('/onboarding/welcome');
            } finally {
              setLoggingOut(false);
            }
          },
        },
      ],
    );
  };

  return (
    <View className="flex-1">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="px-4 mb-4 mt-2">
            <Card onPress={() => router.push('/(tabs)/account/my-horses')}>
              <View className="flex-row items-center gap-3">
                <LinearGradient
                  colors={['#30C7BA', '#0D5C5B']}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 1 }}
                  style={{ width: 48, height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center' }}
                >
                  <Text className="font-bold text-white" style={{ fontSize: 16 }}>
                    {(user.avatarInitial as string) ?? 'M'}
                  </Text>
                </LinearGradient>
                <View className="flex-1">
                  <Text className="font-bold text-ink" style={{ fontSize: 17 }}>{user.name as string}</Text>
                  <Text className="text-[12px] text-ink-50">
                    {(user.email as string) ?? ''} · {formatJoinedLabel(user.createdAt as string)}
                  </Text>
                </View>
                <ChevronRight size={18} color="rgba(27,42,42,0.5)" />
              </View>
            </Card>
          </View>

          <SectionTitle>Paarden</SectionTitle>
          <View className="px-4 gap-2.5 mb-4">
            {active && (
              <Card flat onPress={() => router.push('/(tabs)/account/horse-profile')}>
                <View className="flex-row items-center gap-3">
                  <View className="h-11 w-11 items-center justify-center rounded-full bg-mint-100">
                    <Image
                      source={require('@/assets/images/logo-horse-mark.png')}
                      style={{ width: 28, height: 28, resizeMode: 'contain' }}
                    />
                  </View>
                  <View className="flex-1">
                    <Text className="font-bold text-ink text-[15px]">{active.name as string}</Text>
                    <Text className="text-[12px] text-ink-50">
                      {(active.breed as string)} · {active.age as number} jr
                    </Text>
                  </View>
                  <Chip label="Actief" variant="success" />
                </View>
              </Card>
            )}
            <Bigchip
              title="Voeg paard toe"
              dashed
              icon={<Plus size={20} color="#0D5C5B" />}
              onPress={() => router.push('/(tabs)/account/my-horses')}
            />
          </View>

          {subscription && (
            <>
              <SectionTitle>Abonnement</SectionTitle>
              <View className="px-4 mb-4">
                <Pressable
                  onPress={() => router.push('/(tabs)/account/subscription')}
                  className="rounded-card bg-teal-700 p-[18px]"
                >
                  <View className="flex-row items-start justify-between">
                    <View>
                      <View className="self-start rounded-pill bg-mint-500 px-3 py-1">
                        <Text className="font-semi text-white text-[12px]">{plan.label as string}</Text>
                      </View>
                      <Text className="font-bold text-white mt-2.5" style={{ fontSize: 20 }}>
                        {plan.name as string}
                      </Text>
                      <Text className="text-white/70 mt-0.5 text-[12px]">
                        {(subscription.renewsLabel as string) ?? ''} · € {((subscription.priceCents as number) / 100).toFixed(0)} {plan.priceSuffix as string}
                      </Text>
                    </View>
                    <ChevronRight size={18} color="rgba(255,255,255,0.7)" />
                  </View>
                </Pressable>
              </View>
            </>
          )}

          <SectionTitle>Algemeen</SectionTitle>
          <View className="px-4">
            {settings.map((r) => {
              const Icon = SETTINGS_ICONS[r.iconKey as string] ?? Settings;
              return (
                <Pressable
                  key={r.id}
                  onPress={() => r.route && router.push(r.route as any)}
                  className="flex-row items-center justify-between border-b border-ink-8 py-3"
                >
                  <View className="flex-row items-center gap-3">
                    <Icon size={20} color="rgba(27,42,42,0.7)" />
                    <View>
                      <Text className="font-medium text-ink text-[14px]">{r.title as string}</Text>
                      {r.subtitle ? <Text className="text-[11px] text-ink-50">{r.subtitle as string}</Text> : null}
                    </View>
                  </View>
                  <ChevronRight size={16} color="rgba(27,42,42,0.5)" />
                </Pressable>
              );
            })}
          </View>

          <View className="px-4 pt-6 pb-4">
            <Pressable
              onPress={confirmLogout}
              disabled={loggingOut}
              className={`flex-row items-center justify-center gap-2 rounded-2xl border border-ink-8 bg-white py-3.5 ${
                loggingOut ? 'opacity-60' : 'active:bg-ink-8'
              }`}
            >
              {loggingOut ? (
                <ActivityIndicator color="#C2543E" />
              ) : (
                <LogOut size={18} color="#C2543E" />
              )}
              <Text className="font-semi text-[14px] text-danger">
                {loggingOut ? 'Uitloggen…' : 'Uitloggen'}
              </Text>
            </Pressable>
            <Text className="mt-2 text-center text-[11px] text-ink-50">
              Ingelogd als {(user.email as string) ?? ''}
            </Text>
            <Text className="mt-1 text-center text-[11px] text-ink-50">
              v{Constants.expoConfig?.version ?? '0.0.0'}
            </Text>
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

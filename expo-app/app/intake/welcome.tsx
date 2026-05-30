// First screen the customer sees after starting the protocol-intake. Sets
// expectations (sections, ~30 min, auto-save) and pushes into the section
// overview hub on tap of "Beginnen".

import { View, Text, ScrollView, Image, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { X, ClipboardList, Camera, Heart, ArrowRight } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { useCurrentUser, useHorse } from '@/db/hooks';
import { INTAKE_SCHEMA } from '@/lib/intake/schema';

export default function IntakeWelcome() {
  const user = useCurrentUser();
  const horse = useHorse();
  const firstName = ((user.name as string) ?? 'er').split(' ')[0];
  const horseName = (horse.name as string) ?? 'je paard';

  return (
    <View className="flex-1 bg-teal-900">
      <StatusBar style="light" />
      <SafeAreaView style={{ flex: 1 }}>
        <View className="flex-row items-center justify-between px-4 pt-2 pb-1">
          <IconButton tone="deep" onPress={() => router.back()}>
            <X size={20} color="#fff" />
          </IconButton>
          <Text className="font-semi text-[14px] text-white/80">Protocol-intake</Text>
          <View style={{ width: 36 }} />
        </View>

        <ScrollView contentContainerStyle={{ paddingHorizontal: 24, paddingBottom: 24 }}>
          <View className="my-6 h-14 w-14 items-center justify-center rounded-2xl bg-mint-500">
            <Image
              source={require('@/assets/images/logo-horse-white.png')}
              style={{ width: 32, height: 32, resizeMode: 'contain' }}
            />
          </View>

          <Text
            className="mb-2 font-semi-italic text-mint-200"
            style={{ fontSize: 13, letterSpacing: 0.3 }}
          >
            Hi {firstName} — leuk dat je er bent.
          </Text>
          <Text className="mb-3.5 font-bold text-white" style={{ fontSize: 30, lineHeight: 34 }}>
            Vertel me over <Text className="text-mint-300">{horseName}</Text>.
          </Text>
          <Text className="mb-6 text-[14px] leading-[22px] text-white/80">
            {INTAKE_SCHEMA.length - 1} korte secties, samen ongeveer{' '}
            <Text className="text-white">30 minuten</Text>. Tussentijds opslaan kan altijd; je hoeft
            het niet in één keer af te ronden.
          </Text>

          <View className="gap-3.5 mb-8">
            {[
              {
                Icon: ClipboardList,
                t: 'Open vragen — ik lees ze persoonlijk terug',
                d: 'Hoe meer detail, hoe beter het advies.',
              },
              {
                Icon: Camera,
                t: 'Foto’s helpen mij meekijken',
                d: 'Huid, hoeven, mest — ik vraag het op zijn tijd.',
              },
              {
                Icon: Heart,
                t: 'Binnen 3 werkdagen jouw protocol',
                d: 'Ik bouw het zelf op basis van wat jij vertelt.',
              },
            ].map(({ Icon, t, d }) => (
              <View key={t} className="flex-row gap-3">
                <View className="h-8 w-8 items-center justify-center rounded-lg bg-mint-500/20">
                  <Icon size={16} color="#5FD7CB" />
                </View>
                <View className="flex-1">
                  <Text className="font-bold text-[13.5px] leading-[18px] text-white">{t}</Text>
                  <Text className="mt-0.5 text-[12px] leading-[16px] text-white/60">{d}</Text>
                </View>
              </View>
            ))}
          </View>
        </ScrollView>

        <View className="gap-2 px-6 pb-6 pt-2">
          <Button
            title="Beginnen"
            variant="primary"
            onPress={() => router.replace('/intake/overview' as any)}
            trailing={<ArrowRight size={18} color="#fff" />}
          />
          <Pressable onPress={() => router.back()} className="items-center py-2">
            <Text className="font-semi text-[13px] text-white/70">Later beginnen</Text>
          </Pressable>
        </View>
      </SafeAreaView>
    </View>
  );
}

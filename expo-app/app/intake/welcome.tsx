// First screen the customer sees after starting the protocol-intake. Sets
// expectations (sections, ~30 min, auto-save) and pushes into the section
// overview hub on tap of "Beginnen".

import { View, Text, ScrollView, Image, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { X, ArrowRight } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { useCurrentUser, useHorse } from '@/db/hooks';
import { useIntakeSchema } from '@/lib/intake/schema-provider';

export default function IntakeWelcome() {
  const user = useCurrentUser();
  const horse = useHorse();
  const { schema, disclaimerLong } = useIntakeSchema();
  const disclaimerBody = disclaimerLong.replace(/^\s*Belangrijk vooraf:\s*/i, '');
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
            Hi {firstName}, leuk dat je er bent.
          </Text>
          <Text className="mb-3.5 font-bold text-white" style={{ fontSize: 30, lineHeight: 34 }}>
            Vertel me over <Text className="text-mint-300">{horseName}</Text>.
          </Text>
          <Text className="mb-4 text-[14px] leading-[22px] text-white/80">
            De intake bevat {Math.max(0, schema.length - 1)} korte secties. Tussentijds opslaan kan altijd; je hoeft
            het niet in één keer af te ronden.
          </Text>
          <View className="mb-6 rounded-2xl bg-mint-500/20 p-4">
            <Text className="text-[13px] leading-[19px] text-white">
              <Text className="font-bold">Let op · </Text>
              Vul de intake binnen <Text className="font-bold">2 maanden na aanschaf</Text> in.
              Daarna vervalt de intake.
            </Text>
          </View>

          <View className="rounded-2xl bg-white/10 p-4">
            <Text className="mb-3 font-bold text-[12.5px] leading-[19px] text-white/80">
              Belangrijk vooraf
            </Text>
            <Text className="mb-3 text-[12.5px] leading-[19px] text-white/80">
              Binnen 5 werkdagen ontvang je jouw protocol.
            </Text>
            <Text className="text-[12.5px] leading-[19px] text-white/80">
              {disclaimerBody}
            </Text>
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

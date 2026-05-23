import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { Calendar } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { StickyCTA } from '@/components/ui/StickyCTA';
import { Button } from '@/components/ui/Button';
import { useNextIntakeBooking, useStoreMutations, useTherapist } from '@/db/hooks';

export default function ConnectScreen() {
  const { setOnboarded } = useStoreMutations();
  const therapist = useTherapist();
  const intake = useNextIntakeBooking();

  const finish = () => {
    setOnboarded(true);
    router.replace('/(tabs)/home');
  };

  return (
    <View className="flex-1 bg-canvas">
      <SubHeader title={`Verbind met ${(therapist.name as string) ?? 'Shelley'}`} onBack={() => router.back()} />
      <ScrollView contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 180 }}>
        <View className="mb-5">
          <Eyebrow className="mb-1.5">Stap 3 van 3 · Optioneel</Eyebrow>
          <ProgressBar value={100} />
        </View>

        <View className="mb-4 rounded-2xl bg-teal-700 p-6">
          <View className="mb-4 h-14 w-14 items-center justify-center rounded-full bg-mint-500">
            <Text className="font-bold text-white" style={{ fontSize: 20 }}>
              {(therapist.avatarInitial as string) ?? 'S'}
            </Text>
          </View>
          <Text className="mb-2 font-bold text-white" style={{ fontSize: 22, lineHeight: 28 }}>
            Plan een gratis intake met {(therapist.name as string) ?? 'Shelley'}.
          </Text>
          <Text className="text-white/85" style={{ fontSize: 14, lineHeight: 22 }}>
            {intake?.durationMinutes ?? 30} minuten — we bekijken samen of mijn aanpak past bij jou en je paard. Zonder verplichting.
          </Text>
        </View>

        {intake && (
          <View className="mb-3 rounded-2xl border border-ink-8 bg-white p-4">
            <View className="flex-row items-center gap-3">
              <View className="h-9 w-9 items-center justify-center rounded-xl bg-mint-50">
                <Calendar size={18} color="#0D5C5B" />
              </View>
              <View className="flex-1">
                <Text className="font-bold text-ink text-[14px]">Eerstvolgende slot</Text>
                <Text className="text-[13px] text-ink-50">{intake.slotLabel as string}</Text>
              </View>
            </View>
          </View>
        )}
      </ScrollView>
      <StickyCTA>
        <Button title="Plan intake & ga naar EquiNova" variant="deep" onPress={finish} />
        <Button title="Sla over — ik kijk eerst rond" variant="ghost" onPress={finish} />
      </StickyCTA>
    </View>
  );
}

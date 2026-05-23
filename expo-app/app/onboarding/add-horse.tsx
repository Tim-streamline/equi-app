import { useState } from 'react';
import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { ArrowRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { Field } from '@/components/ui/Field';
import { StickyCTA } from '@/components/ui/StickyCTA';
import { Button } from '@/components/ui/Button';
import { useCurrentHorseId, useCurrentUserId, useHorse, useStoreMutations } from '@/db/hooks';

export default function AddHorseScreen() {
  const horse = useHorse();
  const horseId = useCurrentHorseId();
  const ownerId = useCurrentUserId();
  const { upsertHorse } = useStoreMutations();

  const [name, setName] = useState((horse.name as string) ?? '');
  const [breed, setBreed] = useState((horse.breed as string) ?? '');
  const [age, setAge] = useState(horse.age ? `${horse.age} jaar` : '');
  const [sex, setSex] = useState((horse.sex as string) ?? '');
  const [weight, setWeight] = useState(horse.weightKg ? `${horse.weightKg} kg` : '');
  const [stall, setStall] = useState((horse.stable as string) ?? '');

  const next = () => {
    const ageMatch = age.match(/\d+/);
    const weightMatch = weight.match(/\d+/);
    upsertHorse(horseId, {
      ownerId,
      name,
      breed,
      age: ageMatch ? parseInt(ageMatch[0], 10) : (horse.age as number) || 0,
      sex,
      weightKg: weightMatch ? parseInt(weightMatch[0], 10) : (horse.weightKg as number) || 0,
      stable: stall,
      status: 'active',
    });
    router.push('/onboarding/focus');
  };

  return (
    <View className="flex-1 bg-canvas">
      <SubHeader title="Nieuw paard" onBack={() => router.back()} />
      <ScrollView contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 140 }}>
        <View className="mb-5">
          <Eyebrow className="mb-1.5">Stap 1 van 3</Eyebrow>
          <ProgressBar value={33} />
        </View>
        <Text className="font-bold text-ink mb-2" style={{ fontSize: 24, lineHeight: 30 }}>
          Vertel me over je paard.
        </Text>
        <Text className="mb-6 text-[14px] text-ink-50">
          Deze gegevens vormen de basis voor elk advies in de app.
        </Text>

        <Field label="NAAM" value={name} onChangeText={setName} />
        <View className="flex-row gap-2.5">
          <View className="flex-1"><Field label="RAS" value={breed} onChangeText={setBreed} /></View>
          <View className="flex-1"><Field label="LEEFTIJD" value={age} onChangeText={setAge} /></View>
        </View>
        <View className="flex-row gap-2.5">
          <View className="flex-1"><Field label="GESLACHT" value={sex} onChangeText={setSex} /></View>
          <View className="flex-1"><Field label="GEWICHT" value={weight} onChangeText={setWeight} /></View>
        </View>
        <Field label="STALLING" value={stall} onChangeText={setStall} />
      </ScrollView>
      <StickyCTA>
        <Button title="Volgende" onPress={next} trailing={<ArrowRight size={18} color="#fff" />} />
      </StickyCTA>
    </View>
  );
}

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

export default function AddHorseScreen() {
  const [name, setName] = useState('Nova');
  const [breed, setBreed] = useState('Friese kruising');
  const [age, setAge] = useState('9 jaar');
  const [sex, setSex] = useState('merrie');
  const [weight, setWeight] = useState('540 kg');
  const [stall, setStall] = useState('Manege De Hoeve · Box 4');

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
        <Button
          title="Volgende"
          onPress={() => router.push('/onboarding/focus')}
          trailing={<ArrowRight size={18} color="#fff" />}
        />
      </StickyCTA>
    </View>
  );
}

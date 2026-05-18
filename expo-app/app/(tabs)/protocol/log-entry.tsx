import { useState } from 'react';
import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Camera } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { Field } from '@/components/ui/Field';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Bigchip } from '@/components/ui/Bigchip';
import { StickyCTA } from '@/components/ui/StickyCTA';
import { Button } from '@/components/ui/Button';
import { HORSE } from '@/data/mock';

const MOOD = ['😞', '😕', '😐', '🙂', '😊'];
const MOOD_LBL = ['slecht', 'minder', 'ok', 'goed', 'top'];
const SCORES = ['A', 'B', 'C', 'D'];

export default function LogEntryScreen() {
  const [mood, setMood] = useState(3);
  const [score, setScore] = useState('B');
  const [note, setNote] = useState('');

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Nieuwe observatie"
          onBack={() => router.back()}
          right={
            <Pressable onPress={() => router.back()} hitSlop={8}>
              <Text className="font-semi text-mint-700 text-[14px]">Klaar</Text>
            </Pressable>
          }
        />
        <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: 140 }}>
          <Field
            label="WAT MERK JE OP?"
            rows={3}
            placeholder="Bv. minder krabben aan manen, mest losser dan gisteren..."
            value={note}
            onChangeText={setNote}
          />

          <SectionTitle>Hoe voelt {HORSE.name} zich?</SectionTitle>
          <View className="flex-row gap-2 px-1 pb-4">
            {[1, 2, 3, 4, 5].map((n) => {
              const active = mood === n;
              return (
                <Pressable
                  key={n}
                  onPress={() => setMood(n)}
                  className={`flex-1 items-center rounded-2xl border bg-white py-3 ${active ? 'border-mint-500 bg-mint-50' : 'border-ink-8'}`}
                >
                  <Text style={{ fontSize: 22 }}>{MOOD[n - 1]}</Text>
                  <Text className="mt-0.5 text-[10px] text-ink-50">{MOOD_LBL[n - 1]}</Text>
                </Pressable>
              );
            })}
          </View>

          <SectionTitle>Mest-score</SectionTitle>
          <View className="flex-row gap-2 px-1 pb-5">
            {SCORES.map((s) => {
              const active = score === s;
              return (
                <Pressable
                  key={s}
                  onPress={() => setScore(s)}
                  className={`flex-1 items-center justify-center rounded-2xl border bg-white py-3 ${active ? 'border-mint-500 bg-mint-50' : 'border-ink-8'}`}
                >
                  <Text className="font-bold text-ink" style={{ fontSize: 20 }}>{s}</Text>
                </Pressable>
              );
            })}
          </View>

          <Bigchip
            title="Voeg foto toe"
            description="Bv. huid, mest of voer"
            icon={<Camera size={20} color="#0D5C5B" />}
            className="bg-mint-50 border-transparent"
          />
        </ScrollView>
        <StickyCTA>
          <Button title="Observatie opslaan" onPress={() => router.back()} />
        </StickyCTA>
      </SafeAreaView>
    </View>
  );
}

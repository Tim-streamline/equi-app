import { useMemo, useState } from 'react';
import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { ArrowRight, Check } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { StickyCTA } from '@/components/ui/StickyCTA';
import { Button } from '@/components/ui/Button';
import { Bigchip } from '@/components/ui/Bigchip';
import {
  useCurrentHorseId,
  useFocusForHorse,
  useFocusTopics,
  useStoreMutations,
} from '@/db/hooks';

export default function FocusScreen() {
  const topics = useFocusTopics();
  const horseId = useCurrentHorseId();
  const existing = useFocusForHorse(horseId);
  const { addHorseFocus, removeHorseFocus } = useStoreMutations();

  const initial = useMemo(
    () => new Set(existing.map((e: any) => e.focusTopicId)),
    [existing],
  );
  const [picked, setPicked] = useState<Set<string>>(initial);

  const toggle = (id: string) => {
    const next = new Set(picked);
    if (next.has(id)) {
      next.delete(id);
      removeHorseFocus(horseId, id);
    } else {
      next.add(id);
      addHorseFocus(horseId, id);
    }
    setPicked(next);
  };

  return (
    <View className="flex-1 bg-canvas">
      <SubHeader title="Waar focus je op?" onBack={() => router.back()} />
      <ScrollView contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 140 }}>
        <View className="mb-5">
          <Eyebrow className="mb-1.5">Stap 2 van 3</Eyebrow>
          <ProgressBar value={66} />
        </View>
        <Text className="font-bold text-ink mb-2" style={{ fontSize: 24, lineHeight: 30 }}>
          Wat speelt er nu?
        </Text>
        <Text className="mb-5 text-[14px] text-ink-50">
          Kies één of meerdere thema&apos;s. Dit bepaalt jouw eerste protocol — je kunt het later altijd aanpassen.
        </Text>

        <View className="gap-2.5">
          {topics.map((f: any) => {
            const active = picked.has(f.id);
            return (
              <Bigchip
                key={f.id}
                title={f.title}
                description={f.description}
                active={active}
                onPress={() => toggle(f.id)}
                icon={<Text style={{ fontSize: 20 }}>{f.icon}</Text>}
                trailing={
                  <View
                    className="items-center justify-center rounded-full border-2"
                    style={{
                      width: 22,
                      height: 22,
                      borderColor: active ? '#18BAB0' : 'rgba(27,42,42,0.15)',
                      backgroundColor: active ? '#18BAB0' : 'transparent',
                    }}
                  >
                    {active && <Check size={14} color="#fff" strokeWidth={3} />}
                  </View>
                }
              />
            );
          })}
        </View>
      </ScrollView>
      <StickyCTA>
        <Button
          title="Volgende"
          disabled={picked.size === 0}
          onPress={() => router.push('/onboarding/connect')}
          trailing={<ArrowRight size={18} color="#fff" />}
        />
      </StickyCTA>
    </View>
  );
}

// Final review screen: lists each section with an edit shortcut and submits
// the intake. Automatic attention points remain part of the internal review.

import { useState } from 'react';
import { View, Text, ScrollView, Pressable, Alert } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { ChevronLeft, Check, Send, AlertTriangle } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { MissingQuestionsModal } from '@/components/intake/MissingQuestionsModal';
import { LaminitisSafetyNotice } from '@/components/intake/LaminitisSafetyNotice';
import { useIntakeSchema } from '@/lib/intake/schema-provider';
import {
  hasCriticalAnswers,
  isSectionComplete,
  missingRequired,
  answersForSection,
} from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';

export default function IntakeSubmit() {
  const insets = useSafeAreaInsets();
  const { state, submit, submitting, laminitisBlocked } = useIntake();
  const { schema, noneOptions } = useIntakeSchema();
  const critical = hasCriticalAnswers(state.answers, schema);
  const [showMissing, setShowMissing] = useState(false);

  const missingBySection = schema.map((s) => {
    const a = answersForSection(s, state.answers);
    const fields = missingRequired(s, a, state.answers, noneOptions);
    return { section: s, fields };
  }).filter((item) => item.fields.length > 0);

  const onSubmit = async () => {
    if (missingBySection.length > 0) {
      setShowMissing(true);
      return;
    }
    try {
      await submit();
      router.replace('/intake/sent' as any);
    } catch (error) {
      Alert.alert('Intake niet verstuurd', error instanceof Error ? error.message : 'Probeer opnieuw.');
    }
  };

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <View className="flex-row items-center justify-between gap-3 px-4 pb-3 pt-2">
          <IconButton onPress={() => router.back()}>
            <ChevronLeft size={22} color="#1B2A2A" />
          </IconButton>
          <Text className="flex-1 text-center font-semi text-[17px] text-ink">
            Klaar om te versturen
          </Text>
          <View style={{ width: 36 }} />
        </View>

        <ScrollView
          contentContainerStyle={{ paddingBottom: 120 + insets.bottom, paddingHorizontal: 20 }}
        >
          <Text className="mt-2 font-bold text-ink" style={{ fontSize: 26, lineHeight: 30 }}>
            Tien van tien.{' '}
            <Text className="text-mint-700">Mooi werk.</Text>
          </Text>
          <Text className="mt-2 mb-5 text-[14px] leading-[20px] text-ink-70">
            Ik (Shelley) lees jouw intake binnen 5 werkdagen helemaal door en zet de eerste
            fase van je protocol klaar in de app. Je krijgt een notificatie.
          </Text>

          {critical && (
            <View className="mb-4 flex-row gap-3 rounded-2xl bg-[#FBE9C6] p-3.5">
              <AlertTriangle size={18} color="#8C6420" />
              <Text className="flex-1 text-[13px] leading-[18px] text-[#5A4214]">
                Een of meer antwoorden vragen extra aandacht. Geen probleem, ik kijk er bij
                ontvangst persoonlijk naar voor we starten.
              </Text>
            </View>
          )}

          <View className="mb-4 overflow-hidden rounded-2xl border border-ink-8 bg-white">
            {schema.map((s, i) => {
              const a = answersForSection(s, state.answers);
              const done = isSectionComplete(s, a, state.answers, noneOptions);
              const missingFields = missingRequired(s, a, state.answers, noneOptions);
              const missing = missingFields.length;
              const first = missingFields[0];
              return (
                <Pressable
                  key={s.id}
                  onPress={() =>
                    router.push({ pathname: '/intake/section/[id]', params: { id: s.id, field: first?.id ?? '' } } as any)
                  }
                  className="flex-row items-center gap-3 px-3.5 py-3"
                  style={{
                    borderBottomWidth: i < schema.length - 1 ? 1 : 0,
                    borderBottomColor: 'rgba(27,42,42,0.08)',
                  }}
                >
                  <View
                    className={`h-7 w-7 items-center justify-center rounded-full ${
                      done ? 'bg-mint-500' : 'bg-ink-8'
                    }`}
                  >
                    {done ? (
                      <Check size={14} color="#fff" strokeWidth={3} />
                    ) : (
                      <Text className="font-bold text-[11px] text-ink-50">{s.nr}</Text>
                    )}
                  </View>
                  <View className="flex-1">
                    <Text className="font-semi text-[13.5px] text-ink">{s.title}</Text>
                    {!done && missing > 0 && (
                      <Text className="text-[11px] text-danger">
                        {missing} verplicht veld{missing === 1 ? '' : 'en'}
                      </Text>
                    )}
                  </View>
                  <Text className="font-semi text-[12px] text-mint-700">Bewerk</Text>
                </Pressable>
              );
            })}
          </View>

          <View className="rounded-2xl bg-mint-50 p-3.5">
            <Text className="text-[12.5px] leading-[18px] text-ink-70">
              <Text className="font-bold text-mint-700">Even checken · </Text>
              Klopt alles? Loop nog een keer door je antwoorden voordat je het instuurt. Na het
              insturen kan het niet meer worden aangepast.
            </Text>
          </View>
        </ScrollView>

        <View
          className="absolute bottom-0 left-0 right-0 px-5 pt-3"
          style={{
            backgroundColor: 'rgba(251,248,243,0.96)',
            paddingBottom: insets.bottom + 16,
          }}
        >
          <LaminitisSafetyNotice />
          <Button
            title={submitting ? "Synchroniseren en versturen…" : "Intake versturen"}
            disabled={submitting || !!state.submittedAt || laminitisBlocked}
            variant="primary"
            onPress={onSubmit}
            leading={<Send size={18} color="#fff" />}
          />
        </View>
        <MissingQuestionsModal visible={showMissing} sections={missingBySection} onClose={() => setShowMissing(false)}
          onSelect={(section, field) => {
            setShowMissing(false);
            router.push({ pathname: '/intake/section/[id]', params: { id: section.id, field: field.id } } as any);
          }} />
      </SafeAreaView>
    </View>
  );
}

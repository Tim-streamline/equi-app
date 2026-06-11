// Final review screen — lists each section with an edit shortcut, surfaces
// the number of flagged answers (so the customer knows Shelley will pay extra
// attention to those), and submits the intake.

import { View, Text, ScrollView, Pressable, Alert } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { ChevronLeft, Check, Send, AlertTriangle } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { INTAKE_SCHEMA } from '@/lib/intake/schema';
import {
  countFlags,
  hasCriticalAnswers,
  isSectionComplete,
  missingRequired,
} from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';

export default function IntakeSubmit() {
  const insets = useSafeAreaInsets();
  const { state, submit } = useIntake();
  const flags = countFlags(state.answers);
  const critical = hasCriticalAnswers(state.answers);

  // Per section, the still-missing required questions (by their visible label),
  // so we can both list them inline and surface them in the "you can't send
  // yet" notification when someone taps VERSTUREN too early.
  const missingBySection = INTAKE_SCHEMA.map((s) => {
    const a = state.answers[s.id] ?? {};
    const fields = missingRequired(s, a, state.answers);
    const empty = Object.keys(a).length === 0;
    return { section: s, fields, incomplete: empty || fields.length > 0 };
  }).filter((m) => m.incomplete);

  const onSubmit = () => {
    if (missingBySection.length > 0) {
      // "Pushmelding": tell the customer exactly which question in which
      // subscreen still needs an answer before the form can be sent.
      const lines = missingBySection
        .map((m) => {
          const labels = m.fields.map((f) => `• ${f.label}`).join('\n');
          return `${m.section.nr}. ${m.section.title}\n${labels || '• Nog niet ingevuld'}`;
        })
        .join('\n\n');
      Alert.alert(
        'Nog niet compleet',
        `Vul eerst de volgende vragen in voordat je verstuurt:\n\n${lines}`,
        [{ text: 'Oké' }],
      );
      return;
    }
    submit();
    router.replace('/intake/sent' as any);
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
            Ik (Shelley) lees jouw intake binnen 3 werkdagen helemaal door en stuur het eerste
            protocol terug via de app. Je krijgt een notificatie.
          </Text>

          {critical && (
            <View className="mb-4 flex-row gap-3 rounded-2xl bg-[#FBE9C6] p-3.5">
              <AlertTriangle size={18} color="#8C6420" />
              <Text className="flex-1 text-[13px] leading-[18px] text-[#5A4214]">
                Een of meer antwoorden vragen extra aandacht. Geen probleem — ik kijk er bij
                ontvangst persoonlijk naar voor we starten.
              </Text>
            </View>
          )}

          {flags > 0 && !critical && (
            <View className="mb-4 rounded-2xl bg-mint-50 p-3.5">
              <Text className="text-[13px] leading-[18px] text-ink-70">
                <Text className="font-bold text-mint-700">{flags} aandachtspunten · </Text>
                Ik zal deze als eerste bekijken bij je intake.
              </Text>
            </View>
          )}

          {missingBySection.length > 0 && (
            <View className="mb-4 rounded-2xl bg-[#F4D6CF] p-3.5">
              <Text className="mb-1.5 text-[13px] leading-[18px] text-[#5A2418]">
                <Text className="font-bold">
                  Nog {missingBySection.length} sectie(s) onvolledig ·{' '}
                </Text>
                Vul onderstaande vragen in voordat je verstuurt:
              </Text>
              {missingBySection.map((m) => (
                <View key={m.section.id} className="mt-1.5">
                  <Text className="font-bold text-[12px] text-[#5A2418]">
                    {m.section.nr}. {m.section.title}
                  </Text>
                  {m.fields.length > 0 ? (
                    m.fields.map((f) => (
                      <Text key={f.id} className="text-[12px] leading-[17px] text-[#5A2418]">
                        • {f.label}
                      </Text>
                    ))
                  ) : (
                    <Text className="text-[12px] leading-[17px] text-[#5A2418]">
                      • Nog niet ingevuld
                    </Text>
                  )}
                </View>
              ))}
            </View>
          )}

          <View className="mb-4 overflow-hidden rounded-2xl border border-ink-8 bg-white">
            {INTAKE_SCHEMA.map((s, i) => {
              const a = state.answers[s.id] ?? {};
              const done = isSectionComplete(s, a, state.answers) && Object.keys(a).length > 0;
              const missing = missingRequired(s, a, state.answers).length;
              return (
                <Pressable
                  key={s.id}
                  onPress={() =>
                    router.push({ pathname: '/intake/section/[id]', params: { id: s.id } } as any)
                  }
                  className="flex-row items-center gap-3 px-3.5 py-3"
                  style={{
                    borderBottomWidth: i < INTAKE_SCHEMA.length - 1 ? 1 : 0,
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
          <Button
            title="VERSTUREN"
            variant="primary"
            onPress={onSubmit}
            leading={<Send size={18} color="#fff" />}
          />
        </View>
      </SafeAreaView>
    </View>
  );
}

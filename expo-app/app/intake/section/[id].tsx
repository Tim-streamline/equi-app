// Data-driven section detail screen. The route param `id` selects the
// section, and the fields are rendered straight from the schema through
// <IntakeField/>. The sticky CTA jumps to the next not-yet-completed
// section (or the submit screen on the last one).

import { useMemo } from 'react';
import { View, Text, ScrollView, KeyboardAvoidingView, Platform } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { ChevronLeft, X } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { IntakeField } from '@/components/intake/IntakeField';
import { INTAKE_SCHEMA, getSection } from '@/lib/intake/schema';
import { showField, answeredCount } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';

export default function IntakeSectionScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const insets = useSafeAreaInsets();
  const section = id ? getSection(id) : undefined;
  const { state } = useIntake();
  const sectionId = section?.id ?? '';
  const answers = state.answers[sectionId] ?? {};
  // Filter fields by showIf, but keep sectionheads since they wrap visible
  // children — the renderer just skips numbering them. Hook lives above the
  // missing-section guard so the order stays stable across renders.
  const visible = useMemo(
    () => (section ? section.fields.filter((f) => showField(f, answers, state.answers)) : []),
    [section, answers, state.answers],
  );

  if (!section) {
    return (
      <View className="flex-1 items-center justify-center bg-canvas">
        <Text className="text-ink-50">Sectie niet gevonden.</Text>
      </View>
    );
  }

  // Progress within the current section — how far through the visible fields
  // they are. `answeredCount` only counts values that are genuinely valid for
  // the field (e.g. a radio value still present in its options), so stale
  // answers from an earlier schema version don't inflate the tally.
  const { answered: filled, total } = answeredCount(section, answers, state.answers);
  const pct = total ? Math.round((filled / total) * 100) : 0;

  // Every visible question answered (not just the required ones) flips the
  // counter from red to green.
  const allAnswered = total > 0 && filled === total;

  // Walking field number across the visible (non-sectionhead) fields so the
  // eyebrow numbers reset cleanly per section and stay continuous through
  // sectionhead dividers.
  let fieldCounter = 0;

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <View className="flex-row items-center justify-between gap-2 px-4 pb-3 pt-2">
          <IconButton onPress={() => router.back()}>
            <ChevronLeft size={22} color="#1B2A2A" />
          </IconButton>
          <View className="flex-1 items-center">
            <Text
              className="font-bold uppercase text-ink-50"
              style={{ fontSize: 10.5, letterSpacing: 1.5 }}
            >
              Sectie {section.nr + 1} van {INTAKE_SCHEMA.length}
            </Text>
            <Text
              className="font-bold text-[15px] text-ink"
              numberOfLines={1}
            >
              {section.title}
            </Text>
          </View>
          <IconButton onPress={() => router.replace('/intake/overview' as any)}>
            <X size={20} color="#1B2A2A" />
          </IconButton>
        </View>

        <View className="px-5">
          <View className="h-1 overflow-hidden rounded-pill bg-ink-8">
            <View
              className="h-full rounded-pill bg-mint-500"
              style={{ width: `${pct}%` }}
            />
          </View>
        </View>

        <KeyboardAvoidingView
          style={{ flex: 1 }}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          keyboardVerticalOffset={Platform.OS === 'ios' ? 8 : 0}
        >
          <ScrollView
            contentContainerStyle={{
              paddingHorizontal: 20,
              paddingTop: 16,
              paddingBottom: 140 + insets.bottom,
            }}
            keyboardShouldPersistTaps="handled"
          >
            {section.intro && (
              <Text className="mb-4 text-[13.5px] leading-[20px] text-ink-70">
                {section.intro}
              </Text>
            )}

            {visible.map((f) => {
              if (f.type !== 'sectionhead') fieldCounter += 1;
              return (
                <IntakeField
                  key={f.id}
                  field={f}
                  sectionId={section.id}
                  n={fieldCounter}
                />
              );
            })}
          </ScrollView>
        </KeyboardAvoidingView>

        <View
          className="absolute bottom-0 left-0 right-0 gap-2 px-5 pt-3"
          style={{
            backgroundColor: 'rgba(251,248,243,0.96)',
            paddingBottom: insets.bottom + 16,
          }}
        >
          <Button
            title="Terug naar overzicht"
            variant="primary"
            onPress={() => router.replace('/intake/overview' as any)}
          />
          <View className="flex-row items-center justify-center gap-1.5">
            <View className="h-1.5 w-1.5 rounded-full bg-mint-500" />
            <Text className="text-[11px] text-ink-50">
              Automatisch opgeslagen ·{' '}
              <Text className={allAnswered ? 'text-success' : 'text-danger'}>
                {filled} / {total}
              </Text>{' '}
              vragen beantwoord
            </Text>
          </View>
        </View>
      </SafeAreaView>
    </View>
  );
}

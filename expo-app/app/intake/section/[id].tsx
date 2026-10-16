// Data-driven section detail screen. The route param `id` selects the
// section, and the fields are rendered straight from the schema through
// <IntakeField/>. The sticky CTA jumps to the next not-yet-completed
// section (or the submit screen on the last one).

import { useEffect, useRef, useMemo } from 'react';
import { View, Text, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { ChevronLeft, X } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { IntakeScrollView } from '@/components/intake/IntakeScrollView';
import { IntakeField } from '@/components/intake/IntakeField';
import { useIntakeSchema } from '@/lib/intake/schema-provider';
import { showField, answeredCount, answersForSection, isPhotoSection, isSectionComplete } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';

export default function IntakeSectionScreen() {
  const { id, field: requestedField } = useLocalSearchParams<{ id: string; field?: string }>();
  const scroll = useRef<ScrollView>(null);
  const insets = useSafeAreaInsets();
  const { schema, noneOptions } = useIntakeSchema();
  const section = id ? schema.find((candidate) => candidate.id === id) : undefined;
  const { state, trackActivity } = useIntake();
  useEffect(() => { if (id) trackActivity(id); }, [id, trackActivity]);
  const answers = useMemo(
    () => section ? answersForSection(section, state.answers) : {},
    [section, state.answers],
  );
  // Filter fields by showIf, but keep sectionheads since they wrap visible
  // children — the renderer just skips numbering them. Hook lives above the
  // missing-section guard so the order stays stable across renders.
  const visible = useMemo(
    () => (section ? section.fields.filter((f) => showField(f, answers, state.answers, noneOptions)) : []),
    [section, answers, state.answers, noneOptions],
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
  const { answered: filled, total } = answeredCount(section, answers, state.answers, noneOptions);
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
          <IconButton accessibilityLabel="Terug naar intakeoverzicht" onPress={() => router.dismissTo('/intake/overview')}>
            <ChevronLeft size={22} color="#1B2A2A" />
          </IconButton>
          <View className="flex-1 items-center">
            <Text
              className="font-bold uppercase text-ink-50"
              style={{ fontSize: 10.5, letterSpacing: 1.5 }}
            >
              Sectie {section.nr + 1} van {schema.length}
            </Text>
            <Text
              className="font-bold text-[15px] text-ink"
              numberOfLines={1}
            >
              {section.title}
            </Text>
          </View>
          <IconButton accessibilityLabel="Terug naar intakeoverzicht" onPress={() => router.dismissTo('/intake/overview')}>
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

        <IntakeScrollView scrollRef={scroll} footer={
        <View
          className="gap-2 px-5 pt-3"
          style={{
            backgroundColor: 'rgba(251,248,243,0.96)',
            paddingBottom: insets.bottom + 16,
          }}
        >
          <Button
            title="Opslaan en terug naar overzicht"
            variant="primary"
            onPress={() => router.dismissTo('/intake/overview')}
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
        }>
            {section.intro && (
              <Text className="mb-4 text-[13.5px] leading-[20px] text-ink-70">
                {section.intro}
              </Text>
            )}

            {isPhotoSection(section) && isSectionComplete(section, answers, state.answers, noneOptions) && (
              <Text className="mb-4 font-semi text-mint-700">Foto’s compleet ✓</Text>
            )}
            {visible.map((f) => {
              if (f.type !== 'sectionhead') fieldCounter += 1;
              return (
                <View key={f.id} onTouchStart={() => trackActivity(section.id, f.id)} onLayout={event => { if (requestedField === f.id) scroll.current?.scrollTo({ y: event.nativeEvent.layout.y, animated: false }); }}>
                <IntakeField
                  field={f}
                  sectionId={section.id}
                  n={fieldCounter}
                  noneOptions={noneOptions}
                /></View>
              );
            })}
        </IntakeScrollView>
      </SafeAreaView>
    </View>
  );
}

import { formatIntakeDate } from '@/lib/intake/submission';
// Data-driven section detail screen. The route param `id` selects the
// section, and the fields are rendered straight from the schema through
// <IntakeField/>. The sticky CTA jumps to the next not-yet-completed
// section (or the submit screen on the last one).

import { useCallback, useEffect, useRef, useMemo, useState } from 'react';
import { View, Text, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { Check, ChevronLeft, CloudUpload, X } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { IntakeScrollView } from '@/components/intake/IntakeScrollView';
import { IntakeField } from '@/components/intake/IntakeField';
import { useIntakeSchema } from '@/lib/intake/schema-provider';
import { visibleFieldsForRender, answeredCount, answersForSection, isPhotoSection, isSectionComplete } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';

export default function IntakeSectionScreen() {
  const { id, field: requestedField } = useLocalSearchParams<{ id: string; field?: string }>();
  const scroll = useRef<ScrollView>(null);
  const fieldPositions = useRef<Record<string, number>>({});
  const scrollRequest = useRef({ section: id, field: requestedField, completed: false });
  if (scrollRequest.current.section !== id || scrollRequest.current.field !== requestedField) {
    if (scrollRequest.current.section !== id) fieldPositions.current = {};
    scrollRequest.current = { section: id, field: requestedField, completed: false };
  }
  const [highlightedField, setHighlightedField] = useState<string | null>(requestedField ?? null);
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
    () => (section ? visibleFieldsForRender(section, answers, state.answers, noneOptions) : []),
    [section, answers, state.answers, noneOptions],
  );
  const scrollToRequestedField = useCallback(() => {
    if (!requestedField || scrollRequest.current.completed || !scroll.current) return;
    const y = fieldPositions.current[requestedField];
    if (y === undefined) return;
    // Both the opening effect and layout events can arrive first. Consume the
    // request before scrolling so later layouts cannot pull the user back.
    scrollRequest.current.completed = true;
    scroll.current.scrollTo({ y: Math.max(0, y - 12), animated: false });
  }, [requestedField]);
  useEffect(() => {
    setHighlightedField(requestedField ?? null);
    scrollToRequestedField();
  }, [id, requestedField, scrollToRequestedField]);
  useEffect(() => {
    if (!highlightedField) return;
    const timer = setTimeout(() => setHighlightedField(null), 3000);
    return () => clearTimeout(timer);
  }, [highlightedField]);

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
  const photoSection = isPhotoSection(section);

  // Walking field number across the visible (non-sectionhead) fields so the
  // eyebrow numbers reset cleanly per section and stay continuous through
  // sectionhead dividers.
  let fieldCounter = 0;

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <View className={`flex-row items-center justify-between gap-2 px-4 pb-3 pt-2 ${photoSection ? 'bg-white' : ''}`}>
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

        {photoSection ? <View className="flex-row gap-1 bg-white px-5 pb-4">
          {schema.map((item, index) => <View key={item.id} className={`h-1 flex-1 rounded-pill ${index <= section.nr ? 'bg-mint-500' : 'bg-ink-8'}`} />)}
        </View> : <View className="px-5">
          <View className="h-1 overflow-hidden rounded-pill bg-ink-8">
            <View className="h-full rounded-pill bg-mint-500" style={{ width: `${pct}%` }} />
          </View>
        </View>}

        <IntakeScrollView scrollRef={scroll} footer={
        <View
          className="gap-2 px-5 pt-3"
          style={{
            backgroundColor: photoSection ? '#FFFFFF' : 'rgba(251,248,243,0.96)',
            paddingBottom: insets.bottom + 16,
          }}
        >
          <Button
            title={state.submittedAt ? "Terug naar overzicht" : "Opslaan en terug naar overzicht"}
            variant="primary"
            className={photoSection ? 'rounded-full' : undefined}
            onPress={() => router.dismissTo('/intake/overview')}
          />
          <View className="flex-row items-center justify-center gap-1.5">
            <View className="h-1.5 w-1.5 rounded-full bg-mint-500" />
            <Text className="text-[11px] text-ink-50">
              {state.submittedAt ? `Ingestuurd op ${formatIntakeDate(state.submittedAt)} · ` : 'Automatisch opgeslagen · '}
              <Text className={allAnswered ? 'text-success' : 'text-danger'}>
                {filled} / {total}
              </Text>{' '}
              vragen beantwoord
            </Text>
          </View>
        </View>
        }>
            {photoSection ? <View className="mb-6 rounded-[22px] bg-[#E8F4F2] p-5">
              {!!section.intro && <Text className="text-[15px] leading-[24px] text-[#174B49]">{section.intro.split('\n\n')[0]}</Text>}
              <View className="mt-4 gap-3 border-t border-[#D0E5E2] pt-4">
                <View className="flex-row items-center gap-3">
                  <View className="h-7 w-7 items-center justify-center rounded-full bg-white"><Check size={15} color="#168D87" /></View>
                  <Text className="flex-1 text-[13px] leading-[19px] text-[#315D5B]">Foto’s worden tussentijds automatisch opgeslagen.</Text>
                </View>
                {!!section.presentation?.offline_notice && <View className="flex-row items-center gap-3">
                  <View className="h-7 w-7 items-center justify-center rounded-full bg-white"><CloudUpload size={15} color="#168D87" /></View>
                  <Text className="flex-1 text-[13px] leading-[19px] text-[#315D5B]">{section.presentation.offline_notice}</Text>
                </View>}
              </View>
            </View> : !!(section.intro || section.presentation?.offline_notice) && (
              <View className={section.presentation?.intro_style === 'card' ? 'mb-5 rounded-2xl bg-teal-700 p-5' : 'mb-4'}>
                {!!section.intro && <Text className={section.presentation?.intro_style === 'card' ? 'text-[16px] leading-[24px] text-white' : 'text-[16px] leading-[23px] text-ink-70'}>{section.intro}</Text>}
                {!!section.presentation?.offline_notice && <Text className={section.presentation?.intro_style === 'card' ? 'mt-4 text-[14px] leading-[21px] text-white' : 'mt-4 text-sm text-ink-70'}>{section.presentation.offline_notice}</Text>}
              </View>
            )}

            {photoSection && isSectionComplete(section, answers, state.answers, noneOptions) && (
              <Text className="mb-4 font-semi text-mint-700">Foto’s compleet ✓</Text>
            )}
            {visible.map((f) => {
              if (f.type !== 'sectionhead') fieldCounter += 1;
              return (
                <View key={f.id}
                  className={`${photoSection && f.type === 'photo' ? 'mb-5 rounded-[24px] bg-white p-5 shadow-sm' : ''} ${highlightedField === f.id ? 'rounded-2xl border-2 border-[#D49E36] bg-[#FFF8E9] p-3' : ''}`}
                  onTouchStart={() => trackActivity(section.id, f.id)}
                  onLayout={event => {
                    fieldPositions.current[f.id] = event.nativeEvent.layout.y;
                    if (requestedField === f.id) scrollToRequestedField();
                  }}>
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

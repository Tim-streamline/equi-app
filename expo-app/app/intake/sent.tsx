import { formatIntakeDate } from '@/lib/intake/submission';
// Post-submit confirmation. Shows the status timeline and an exit back to
// the home tab — the screen is the last stop in the intake stack and the
// "Terug naar de app" button pops the user out cleanly.

import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { Check } from 'lucide-react-native';

import { Button } from '@/components/ui/Button';
import { useCurrentUser } from '@/db/hooks';
import { useIntake } from '@/lib/intake/store';
import { BRAND_NAME } from '@/constants/brand';

function formatSubmittedLabel(iso: string | null): string {
  if (!iso) return 'Zojuist';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return 'Zojuist';
  const today = new Date();
  const sameDay =
    d.getFullYear() === today.getFullYear() &&
    d.getMonth() === today.getMonth() &&
    d.getDate() === today.getDate();
  const hhmm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  return sameDay ? `Vandaag · ${hhmm}` : `${d.toLocaleDateString('nl-NL')} · ${hhmm}`;
}

export default function IntakeSent() {
  const insets = useSafeAreaInsets();
  const user = useCurrentUser();
  const { state } = useIntake();
  const firstName = ((user.name as string) ?? 'jou').split(' ')[0];
  const submittedLabel = formatSubmittedLabel(state.submittedAt);

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView
          contentContainerStyle={{
            paddingTop: 60,
            paddingBottom: 120 + insets.bottom,
            paddingHorizontal: 24,
            alignItems: 'center',
          }}
        >
          <View className="mb-7 items-center justify-center">
            <View className="h-[120px] w-[120px] items-center justify-center rounded-full bg-mint-50">
              <View className="h-[84px] w-[84px] items-center justify-center rounded-full bg-mint-500">
                <Check size={42} color="#fff" strokeWidth={3} />
              </View>
            </View>
          </View>

          <Text
            className="mb-2 font-semi-italic text-teal-600"
            style={{ fontSize: 13, letterSpacing: 0.2 }}
          >
            Dankjewel, {firstName}!
          </Text>
          <Text
            className="mb-3 text-center font-bold text-ink"
            style={{ fontSize: 28, lineHeight: 32 }}
          >
            Je intake is verzonden!
          </Text>
          <Text
            className="mb-7 max-w-[280px] text-center text-[14px] leading-[20px] text-ink-70"
          >
            We nemen je antwoorden zorgvuldig door en gaan aan de slag met je persoonlijke herstelplan.
            {'\n\n'}
            Binnen 5 werkdagen staat de eerste fase voor je klaar in {BRAND_NAME}. Je ontvangt een melding via de mail
            zodra je kunt beginnen.
          </Text>

          <Text className="mb-4 text-center text-ink">Verzonden op {formatIntakeDate(state.submittedAt)}</Text>
          <Button title="Intake bekijken" onPress={() => router.replace('/intake/overview')} />
          <View className="mt-4 w-full gap-4 rounded-2xl border border-ink-8 bg-white p-4">
            <Text className="font-semi text-[13.5px] text-ink">Wat gebeurt er nu?</Text>
            <TimelineStep when={submittedLabel} what="Je intake is succesvol verzonden" state="done" />
            <TimelineStep when="Binnen 24 uur" what="We nemen je intake door" state="now" />
            <TimelineStep when="Binnen 5 werkdagen" what="De eerste fase van je herstelplan staat klaar" state="todo" />
            <TimelineStep when="Zodra je herstelplan klaarstaat" what="Je kunt aan de slag met de eerste fase" state="todo" />
          </View>
        </ScrollView>

        <View
          className="absolute bottom-0 left-0 right-0 px-5 pt-3"
          style={{ paddingBottom: insets.bottom + 16 }}
        >
          <Button
            title="Terug naar de app"
            variant="primary"
            onPress={() => router.replace('/(tabs)/(pager)/home')}
          />
        </View>
      </SafeAreaView>
    </View>
  );
}

function TimelineStep({
  when,
  what,
  state,
}: {
  when: string;
  what: string;
  state: 'done' | 'now' | 'todo';
}) {
  // Three-state dot: filled mint for done; outlined mint for "now" (the
  // active expectation); muted ink for future steps.
  const dotClass =
    state === 'done'
      ? 'bg-mint-500 border-mint-500'
      : state === 'now'
        ? 'bg-white border-mint-500'
        : 'bg-white border-ink-15';
  return (
    <View className="flex-row gap-3">
      <View className={`mt-1 h-3 w-3 rounded-full border-2 ${dotClass}`} />
      <View className="flex-1">
        <Text className="text-[11px] text-ink-70">{when}</Text>
        <Text
          className={`mt-0.5 font-semi text-[13.5px] ${
            state === 'todo' ? 'text-ink-70' : 'text-ink'
          }`}
        >
          {what}
        </Text>
      </View>
    </View>
  );
}

// Entry-banner card shown on Home + Protocol tab. Decides its own copy and
// CTA based on intake state (never started / in progress / submitted). When
// nothing is actionable (already submitted) it renders null so the entry
// vanishes automatically.

import { View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { ClipboardList, ArrowRight, Check } from 'lucide-react-native';

import { intakeProgress } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';
import { useActiveProtocolForHorse } from '@/db/hooks';

type Props = {
  /** Compact variant tones down padding when used as a Home banner. */
  variant?: 'banner' | 'standalone';
};

export function IntakeEntryCard({ variant = 'banner' }: Props) {
  const { state, loaded } = useIntake();
  const protocol = useActiveProtocolForHorse();

  // Pre-hydration the safer default is to render nothing — avoids a
  // "start your intake" flash on screens where the user already submitted.
  if (!loaded) return null;
  if (state.submittedAt) return null;
  // If the therapist has already delivered a protocol, the intake step is
  // implicitly complete — don't nag the user to re-do it.
  if (protocol) return null;

  const { done, total, pct } = intakeProgress(state.answers);
  const hasStarted = Object.keys(state.answers).length > 0;

  const headline = hasStarted ? 'Ga verder met je intake' : 'Start jouw protocol-intake';
  const sub = hasStarted
    ? `${done} van ${total} secties klaar — verder waar je gebleven was.`
    : `${total} korte secties — samen ongeveer 30 minuten. Shelley bouwt jouw protocol op basis hiervan.`;
  const ctaLabel = hasStarted ? 'Verder' : 'Beginnen';

  const onPress = () => {
    router.push('/intake' as any);
  };

  const wrap =
    variant === 'banner'
      ? 'mx-4 mb-4 overflow-hidden rounded-card bg-teal-700 px-4 py-4'
      : 'rounded-card bg-teal-700 px-4 py-4';

  return (
    <Pressable onPress={onPress} className={wrap}>
      <View
        className="absolute"
        style={{
          right: -40,
          bottom: -50,
          width: 140,
          height: 140,
          borderRadius: 70,
          backgroundColor: '#18BAB0',
          opacity: 0.18,
        }}
      />
      <View className="flex-row items-start gap-3">
        <View className="h-10 w-10 items-center justify-center rounded-xl bg-mint-500">
          <ClipboardList size={20} color="#fff" />
        </View>
        <View className="flex-1">
          <Text
            className="font-bold uppercase text-mint-200"
            style={{ fontSize: 10.5, letterSpacing: 1.6 }}
          >
            Protocol-intake
          </Text>
          <Text className="mt-1 font-bold text-white" style={{ fontSize: 17, lineHeight: 22 }}>
            {headline}
          </Text>
          <Text className="mt-1 text-[12.5px] leading-[18px] text-white/80">{sub}</Text>

          {hasStarted && (
            <View className="mt-3 h-1 overflow-hidden rounded-pill bg-white/15">
              <View
                className="h-full rounded-pill bg-mint-400"
                style={{ width: `${pct}%` }}
              />
            </View>
          )}
        </View>
        <View className="h-7 w-7 items-center justify-center rounded-full bg-mint-500">
          {hasStarted && done === total ? (
            <Check size={14} color="#fff" strokeWidth={3} />
          ) : (
            <ArrowRight size={14} color="#fff" />
          )}
        </View>
      </View>

      {hasStarted && (
        <View className="mt-2.5 flex-row items-center justify-between">
          <Text className="text-[11px] text-white/65">{pct}% klaar</Text>
          <Text className="font-semi text-[11px] text-mint-200">{ctaLabel} →</Text>
        </View>
      )}
    </Pressable>
  );
}

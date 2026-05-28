// Entry redirect for /intake. Decides which screen to land on based on the
// current state of the intake: never started → welcome; in progress →
// overview hub; already submitted → confirmation screen.

import { useEffect } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { router } from 'expo-router';

import { useIntake } from '@/lib/intake/store';

export default function IntakeIndex() {
  const { state, loaded } = useIntake();

  useEffect(() => {
    if (!loaded) return;
    if (state.submittedAt) {
      router.replace('/intake/sent' as any);
      return;
    }
    const hasAnyAnswer = Object.keys(state.answers).length > 0;
    router.replace((hasAnyAnswer ? '/intake/overview' : '/intake/welcome') as any);
  }, [loaded, state.answers, state.submittedAt]);

  return (
    <View className="flex-1 items-center justify-center bg-canvas">
      <ActivityIndicator color="#108A82" />
    </View>
  );
}

import { Redirect, Stack, useSegments } from 'expo-router';
import { useIntake } from '@/lib/intake/store';

// File-based routing auto-discovers screens; no explicit <Stack.Screen> needed
// here unless we want per-screen options. Header is hidden globally — each
// screen renders its own SubHeader / themed top bar.
export default function IntakeLayout() {
  const { state, loaded } = useIntake();
  const segments = useSegments();
  if (!loaded) return null;
  if (state.submittedAt && segments.at(-1) !== 'sent') return <Redirect href="/intake/sent" />;
  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: '#FBF8F3' },
      }}
    />
  );
}

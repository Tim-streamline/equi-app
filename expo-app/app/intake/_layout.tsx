import { Stack } from 'expo-router';

// File-based routing auto-discovers screens; no explicit <Stack.Screen> needed
// here unless we want per-screen options. Header is hidden globally — each
// screen renders its own SubHeader / themed top bar.
export default function IntakeLayout() {
  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: '#FBF8F3' },
      }}
    />
  );
}

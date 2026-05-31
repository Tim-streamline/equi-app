import { Stack } from 'expo-router';

export default function TabsLayout() {
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#FBF8F3' } }}>
      <Stack.Screen name="(pager)" />
      <Stack.Screen name="scanner" />
      <Stack.Screen name="protocol/log-entry" />
      <Stack.Screen name="library/article/[id]" />
      <Stack.Screen name="library/video/[id]" />
      <Stack.Screen name="account/horse-profile" />
      <Stack.Screen name="account/my-horses" />
      <Stack.Screen name="account/subscription" />
      <Stack.Screen name="account/community" />
    </Stack>
  );
}

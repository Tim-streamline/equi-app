import { Redirect, useLocalSearchParams } from 'expo-router';

export default function LegacyCommunityThread() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <Redirect href={{ pathname: '/(tabs)/community/thread/[id]', params: { id } }} />;
}

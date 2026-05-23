import { Redirect } from 'expo-router';
import { useDb } from '@/db/provider';

export default function Index() {
  const { isLoggedIn } = useDb();
  return <Redirect href={isLoggedIn ? '/(tabs)/home' : '/onboarding/welcome'} />;
}

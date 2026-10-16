import { Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { SubHeader } from '@/components/ui/SubHeader';
import { SubscriptionRenewal } from '@/components/subscription/SubscriptionRenewal';

export default function SubscriptionScreen() {
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title="Abonnement" onBack={() => router.back()} />
    <SubscriptionRenewal />
  </SafeAreaView>;
}

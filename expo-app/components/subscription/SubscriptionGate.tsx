import { useEffect, useState, type ReactNode } from 'react';
import { ActivityIndicator, AppState, Pressable, Text, View } from 'react-native';
import { useQuery, useStatus } from '@powersync/react';
import { useDb } from '@/db/provider';
import { subscriptionActive } from '@/lib/subscription';
import { SubscriptionRenewal } from './SubscriptionRenewal';

export function SubscriptionGate({ children }: { children: ReactNode }) {
  const { currentUserId, isLoggedIn } = useDb();
  if (!isLoggedIn) return children;
  return <AccountSubscriptionGate key={currentUserId}>{children}</AccountSubscriptionGate>;
}

function AccountSubscriptionGate({ children }: { children: ReactNode }) {
  const { currentUserId, refreshSubscriptionAccess } = useDb();
  const { data: subscriptions, isLoading } = useQuery<Record<string, any>>("SELECT s.* FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? AND p.slug != 'free'", [currentUserId]);
  const status = useStatus();
  const [now, setNow] = useState(() => new Date());
  const [needsRefresh, setNeedsRefresh] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const subscribed = subscriptions.some(subscription => subscriptionActive(subscription, now));
  const resolved = !isLoading && (subscriptions.length > 0 || !!status.hasSynced);
  useEffect(() => {
    // Do not treat initial query hydration as expiry. A cached active
    // subscription must still open immediately when the device is offline.
    if (resolved && !subscribed) setNeedsRefresh(true);
  }, [resolved, subscribed]);
  useEffect(() => {
    if (!subscribed || !needsRefresh) return;
    const controller = new AbortController();
    setError(null);
    void refreshSubscriptionAccess(controller.signal).then(() => {
      if (!controller.signal.aborted) setNeedsRefresh(false);
    }).catch(cause => {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : 'Je gegevens konden niet worden geladen.');
    });
    return () => controller.abort();
  }, [subscribed, needsRefresh, attempt, refreshSubscriptionAccess]);
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000);
    const listener = AppState.addEventListener('change', () => setNow(new Date()));
    return () => { clearInterval(timer); listener.remove(); };
  }, []);
  if (subscribed && needsRefresh) return <View className="flex-1 items-center justify-center gap-3 p-6">
    {error ? <>
      <Text accessibilityRole="alert">{error}</Text>
      <Pressable accessibilityRole="button" onPress={() => setAttempt(value => value + 1)}><Text>Opnieuw proberen</Text></Pressable>
    </> : <><ActivityIndicator /><Text>Je abonnement is actief. Je gegevens worden geladen…</Text></>}
  </View>;
  if (subscribed) return children;
  if (isLoading || (!subscriptions.length && !status.hasSynced)) return <View className="flex-1 items-center justify-center gap-3"><ActivityIndicator /><Text>Je abonnement wordt geladen…</Text></View>;
  return <SubscriptionRenewal />;
}

import { useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { Button } from '@/components/ui/Button';
import { libraryRequest, useLibraryResource } from '@/hooks/useLibraryResource';
import { creditDate, type CreditSummary } from '@/lib/credits';

export function BasicCancellation() {
  const { data, refresh } = useLibraryResource<CreditSummary>('/credits');
  const pending = useRef(false);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function cancel() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError('');
    try { await libraryRequest('/credits/cancel-basic', 'POST'); await refresh(); setConfirming(false); }
    catch (cause) { setError(cause instanceof Error ? cause.message : 'Opzeggen is niet gelukt.'); }
    finally { pending.current = false; setBusy(false); }
  }
  if (!data?.hasBasic) return null;
  return <View className="mt-5 gap-3">
    {data.endsAt ? <Text className="text-ink-70">Je Basic-abonnement loopt tot {creditDate(data.endsAt)}</Text> : confirming ? <>
      <Text className="text-ink">Basic opzeggen? Je behoudt toegang tot het einde van je betaalde periode. Daarna vervallen je Basic-credits. Je ontgrendelde items blijven behouden.</Text>
      <Button title="Opzegging bevestigen" disabled={busy} onPress={() => void cancel()} />
      <Button title="Annuleren" variant="text" disabled={busy} onPress={() => setConfirming(false)} />
    </> : <Button title="Basic opzeggen" variant="text" onPress={() => setConfirming(true)} />}
    {!!error && <Text accessibilityRole="alert" className="text-ink-70">{error}</Text>}
  </View>;
}

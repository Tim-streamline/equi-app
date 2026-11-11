import { useState } from 'react';
import { Platform, Text, View } from 'react-native';
import { useDb } from '@/db/provider';
import { Button } from '@/components/ui/Button';
import { PLAIN_CARD } from '@/components/ui/styles';

export function ResetSyncSettings() {
  const { resetSync } = useDb();
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const reset = async () => {
    if (busy) return;
    setBusy(true); setError(null);
    try { await resetSync(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : 'Herstellen is niet gelukt. Probeer opnieuw.'); }
    finally { setBusy(false); }
  };
  return <View className={`mt-5 gap-4 ${PLAIN_CARD}`}>
    <Text className="font-heading text-xl text-ink">Reset sync</Text>
    <Text className="font-body text-base text-ink">Loopt de synchronisatie vast? Wis je lokale gegevens en laad opnieuw wat online is opgeslagen.</Text>
    {confirming ? <>
      <Text className="font-body text-base text-ink">Alle wijzigingen en foto's die nog niet online zijn opgeslagen, gaan verloren. Dit kan niet ongedaan worden gemaakt. Je online gegevens blijven bewaard en je blijft ingelogd. Je hebt internet nodig.</Text>
      {Platform.OS === 'web' && <Text className="font-body text-base text-ink">Dit herstelt dit tabblad. Sluit eerst andere tabbladen van de app.</Text>}
      {error && <Text accessibilityRole="alert" className="font-body text-base text-red-700">{error}</Text>}
      <Button title="Lokale wijzigingen wissen en herstellen" variant="deep" disabled={busy} onPress={() => void reset()} />
      <Button title="Annuleren" variant="ghost" disabled={busy} onPress={() => { setConfirming(false); setError(null); }} />
    </> : <Button title="Reset sync…" variant="ghost" onPress={() => setConfirming(true)} />}
  </View>;
}

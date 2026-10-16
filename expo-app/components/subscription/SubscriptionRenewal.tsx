import { useState } from 'react';
import { Text, View } from 'react-native';
import { Button } from '@/components/ui/Button';
import { dashboardRequest } from '@/hooks/useHorseDashboard';

export function SubscriptionRenewal() {
  const [busy, setBusy] = useState(false), [message, setMessage] = useState('');
  async function renew() {
    setBusy(true); setMessage('');
    try {
      await dashboardRequest('/api/subscription/renew', {});
      setMessage('Je abonnement is actief. Je gegevens worden geladen…');
    }
    catch { setMessage('Je abonnement kon niet worden geactiveerd. Controleer je verbinding en probeer het opnieuw.'); }
    finally { setBusy(false); }
  }
  return <View className="flex-1 justify-center gap-5 bg-canvas px-6" style={{ width: '100%', maxWidth: 560, alignSelf: 'center' }}>
    <Text className="font-bold text-[28px] text-teal-700">Activeer je abonnment</Text>
    <Text className="text-[16px] leading-6 text-ink">Met een actief abonnement heb je toegang tot alle functies van EquiApp. Een persoonlijk protocol koop je apart voor je paard.</Text>
    <Text>Je paarden, protocollen en intake-antwoorden blijven bewaard.</Text>
    <Button title={busy ? 'Even geduld…' : 'Abonnement activeren'} disabled={busy} onPress={() => void renew()} />
    {!!message && <Text accessibilityRole="alert">{message}</Text>}
  </View>;
}

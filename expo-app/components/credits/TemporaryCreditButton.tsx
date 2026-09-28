import { useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { libraryRequest } from '@/hooks/useLibraryResource';
import { Button } from '@/components/ui/Button';

const requestKey = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
  const value = Math.floor(Math.random() * 16);
  return (c === 'x' ? value : (value & 3) | 8).toString(16);
});

/** Temporary seven-credit action, shared by Account and locked library items. */
export function TemporaryCreditButton({ onAdded, disabled = false }: { onAdded: () => Promise<void>; disabled?: boolean }) {
  const pending = useRef(false);
  const key = useRef<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  async function add() {
    if (pending.current || disabled) return;
    pending.current = true; setBusy(true); setMessage('');
    try {
      await libraryRequest('/credits/temporary-top-up', 'POST', undefined, { requestKey: key.current ??= requestKey() });
      // Only reuse the request key for an uncertain API result, never for a new click.
      key.current = null;
      setMessage('7 credits toegevoegd.');
      await onAdded();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Credits toevoegen is niet gelukt. Probeer opnieuw.');
    } finally { pending.current = false; setBusy(false); }
  }
  return <View className="gap-2">
    <Button title={busy ? 'Credits toevoegen…' : 'Credits bijkopen'} disabled={busy || disabled} onPress={() => void add()} />
    {!!message && <Text accessibilityRole="alert" className="text-ink-70">{message}</Text>}
  </View>;
}

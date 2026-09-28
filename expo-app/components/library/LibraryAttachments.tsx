import { useRef, useState } from 'react';
import { Linking, Platform, Text, View } from 'react-native';
import { FileText } from 'lucide-react-native';
import { libraryRequest } from '@/hooks/useLibraryResource';
import { accountSession } from '@/lib/account-session';
import { Button } from '@/components/ui/Button';
import { SectionTitle } from '@/components/ui/SectionTitle';

export type LibraryAttachment = { id: string; title: string; name: string };

export function LibraryAttachments({ itemId, attachments }: { itemId: string; attachments: LibraryAttachment[] }) {
  const pending = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function open(attachment: LibraryAttachment) {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError('');
    const revision = accountSession.revision;
    try {
      const { url } = await libraryRequest<{ url: string }>(`/${encodeURIComponent(itemId)}/attachments/${encodeURIComponent(attachment.id)}/open`, 'POST');
      if (revision !== accountSession.revision) return;
      if (Platform.OS === 'web') globalThis.location.assign(url);
      else await Linking.openURL(url);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'PDF openen is niet gelukt.'); }
    finally { pending.current = false; setBusy(false); }
  }
  if (!attachments.length) return null;
  return <>
    <SectionTitle>Bijlagen</SectionTitle>
    <View className="gap-3 px-5">
      {attachments.map(attachment => <View key={attachment.id} className="gap-3 rounded-xl border border-ink-8 p-4">
        <View className="flex-row items-center gap-2"><FileText size={20} color="#127A79" /><Text className="flex-1 font-semi text-ink">{attachment.title}</Text><Text className="text-xs text-ink-50">PDF</Text></View>
        <Button title="PDF openen" variant="ghost" disabled={busy} onPress={() => void open(attachment)} />
      </View>)}
      {!!error && <Text accessibilityRole="alert" className="text-red-700">{error}</Text>}
    </View>
  </>;
}

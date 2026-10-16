import { useEffect, useRef, useState } from 'react';
import { View, Text, Pressable, Image, Platform, ActivityIndicator } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { Camera, FileText, Trash2 } from 'lucide-react-native';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { useDb } from '@/db/provider';
import { accountSession } from '@/lib/account-session';
import { attachmentStorage, saveAttachment } from '@/lib/intake/attachments/storage';
import { onAttachmentsChanged } from '@/lib/intake/attachments/events';
import type { LocalAttachment } from '@/lib/intake/attachments/types';
import { useIntake } from '@/lib/intake/store';

function fileName(reference: string) {
  try { return decodeURIComponent(reference.split(':').slice(2).join(':')) || 'Bijlage'; } catch { return 'Bijlage'; }
}
function useLocalAttachment(reference: string) {
  const { currentUserId } = useDb();
  const [record, setRecord] = useState<LocalAttachment | null | undefined>(undefined);
  useEffect(() => {
    let active = true;
    setRecord(undefined);
    const refresh = () => {
      if (currentUserId) void attachmentStorage.get(currentUserId, reference.split(':')[1])
        .then(value => { if (active) setRecord(value); }).catch(() => { if (active) setRecord(null); });
      else setRecord(null);
    };
    refresh();
    const unsubscribe = onAttachmentsChanged(refresh);
    return () => { active = false; unsubscribe(); };
  }, [currentUserId, reference]);
  return record;
}
function UploadStatus({ reference }: { reference: string }) {
  const record = useLocalAttachment(reference);
  return record && !record.uploaded ? <Text accessibilityLiveRegion="polite" className="text-xs text-ink-50">
    {record.error ? 'Opgeslagen op dit apparaat · upload wordt opnieuw geprobeerd' : 'Opgeslagen op dit apparaat · wacht op upload'}
  </Text> : null;
}
export function Photo({ reference }: { reference: string }) {
  const local = useLocalAttachment(reference);
  const [source, setSource] = useState<{ uri: string; headers?: Record<string, string> }>();
  useEffect(() => {
    let active = true;
    setSource(undefined);
    let objectUrl: string | undefined;
    const controller = new AbortController();
    void (async () => {
      if (local === undefined) return;
      if (local) {
        if (local.blob) { objectUrl = URL.createObjectURL(local.blob); setSource({ uri: objectUrl }); }
        else if (local.uri) setSource({ uri: local.uri });
        return;
      }
      const token = await getOrMintToken();
      if (!token || !active) return;
      const uri = `${getApiBaseUrl()}/api/intake-media/${reference.split(':')[1]}`;
      const headers = { Authorization: `Bearer ${token}` };
      if (Platform.OS === 'web') {
        const response = await fetch(uri, { headers, signal: controller.signal });
        if (!response.ok) return;
        const blob = await response.blob();
        if (!active) return;
        objectUrl = URL.createObjectURL(blob);
        setSource({ uri: objectUrl });
      } else setSource({ uri, headers });
    })().catch(() => {});
    return () => { active = false; controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [reference, local]);
  return source ? <Image source={source} style={{ width: 80, height: 80, borderRadius: 12 }} /> : <Camera size={24} color="#108a82" />;
}
export function AttachmentField({ value, onChange, section, field, photo, max, renderMetadata }: {
  max?: number; renderMetadata?: (reference: string) => React.ReactNode;
  value: string[]; onChange: (value: string[]) => void; section: string; field: string; photo: boolean;
}) {
  const { ensureBooking, horseId } = useIntake();
  const { currentUserId } = useDb();
  const latest = useRef({ value, onChange });
  latest.current = { value, onChange };
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  const bloodTest = section === 'klacht' && field === 'bloedonderzoek';
  const limitReached = value.filter(item => item.startsWith('attachment:')).length >= (max ?? (bloodTest ? 5 : Infinity));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function add() {
    if (busy || limitReached) return;
    setBusy(true); setError('');
    const revision = accountSession.revision;
    const checkSession = () => {
      if (!mounted.current || revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
    };
    try {
      // Open the browser's file chooser directly from the user gesture.
      const picked = photo
        ? await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.85 })
        : await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], copyToCacheDirectory: true });
      if (picked.canceled || !picked.assets?.length) return;
      const asset = picked.assets[0];
      const size = 'size' in asset ? asset.size : 'fileSize' in asset ? asset.fileSize : undefined;
      if (size && size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
      const id = await ensureBooking();
      checkSession();
      if (!currentUserId) throw new Error('Meld je opnieuw aan om een bijlage toe te voegen.');
      const file = 'file' in asset ? asset.file : undefined;
      const originalName = ('name' in asset ? asset.name : asset.fileName) || (photo ? 'foto.jpg' : 'document.pdf');
      const name = originalName.split(/[\\/]/).pop()!.slice(0, 255);
      const mime = asset.mimeType || (photo ? 'image/jpeg' : 'application/pdf');
      const allowed = photo ? ['image/jpeg', 'image/png', 'image/webp'] : ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
      if (!allowed.includes(mime)) throw new Error(photo ? 'Kies een JPG-, PNG- of WebP-foto.' : 'Kies een PDF-, JPG-, PNG- of WebP-bestand.');
      const attachmentId = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
      await saveAttachment({ id: attachmentId, userId: currentUserId, bookingId: id, horseId, section, field, name, mime, uploaded: false },
        { uri: asset.uri, file, name, mime });
      checkSession();
      // The durable file exists before its reference enters the offline answer queue.
      latest.current.onChange([...latest.current.value.filter(item => item.startsWith('attachment:')), `attachment:${attachmentId}:${encodeURIComponent(name)}`]);
    } catch (error) { setError(error instanceof Error && error.name !== 'AbortError' ? error.message : 'Opslaan op dit apparaat mislukt. Probeer opnieuw.'); }
    finally { setBusy(false); }
  }
  return <View>
    <View className="mb-2 flex-row flex-wrap gap-3">
      {value.map((reference, index) => <View key={`${reference}-${index}`} style={renderMetadata ? { width: 180 } : undefined} className="max-w-[180px] gap-2 rounded-xl bg-white p-3">
        {photo && reference.startsWith('attachment:') ? <Photo reference={reference} /> : <FileText size={22} color="#108a82" />}
        <Text className="text-xs text-ink">{reference.startsWith('attachment:') ? fileName(reference) : 'Oude testbijlage: voeg opnieuw toe'}</Text>
        {reference.startsWith('attachment:') && <UploadStatus reference={reference} />}
        {renderMetadata?.(reference)}
        <Pressable disabled={busy} accessibilityLabel="Bijlage verwijderen" onPress={() => onChange(value.filter((_, i) => i !== index))}><Trash2 size={18} color="#9b3f3f" /></Pressable>
      </View>)}
    </View>
    <Pressable accessibilityRole="button" disabled={busy || limitReached} onPress={() => void add()} className="flex-row items-center justify-center gap-2 rounded-xl border border-dashed border-ink-15 bg-white p-4">
      {busy ? <ActivityIndicator color="#108a82"/> : photo ? <Camera size={20} color="#108a82"/> : <FileText size={20} color="#108a82"/>}
      <Text className="font-semi text-mint-700">{busy ? 'Opslaan…' : photo ? 'Foto toevoegen' : bloodTest ? 'Bloeduitslag toevoegen' : 'Document toevoegen'}</Text>
    </Pressable>
    {bloodTest && <Text className="mt-1 text-xs text-ink-50">Maximaal 5 bestanden</Text>}
    <Text className="mt-1 text-xs text-ink-50">Ook offline toe te voegen. Bestanden worden automatisch geüpload zodra je verbinding hebt.</Text>
    {!!error && <Text accessibilityRole="alert" className="mt-2 text-sm text-red-700">{error}</Text>}
  </View>;
}

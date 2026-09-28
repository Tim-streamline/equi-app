import { useEffect, useState } from 'react';
import { View, Text, Pressable, Image, Platform, ActivityIndicator } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { Camera, FileText, Trash2 } from 'lucide-react-native';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { useIntake } from '@/lib/intake/store';

function fileName(reference: string) {
  try { return decodeURIComponent(reference.split(':').slice(2).join(':')) || 'Bijlage'; } catch { return 'Bijlage'; }
}
function Photo({ reference }: { reference: string }) {
  const [source, setSource] = useState<{ uri: string; headers?: Record<string, string> }>();
  useEffect(() => {
    let active = true;
    let objectUrl: string | undefined;
    const controller = new AbortController();
    void (async () => {
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
  }, [reference]);
  return source ? <Image source={source} style={{ width: 80, height: 80, borderRadius: 12 }} /> : <Camera size={24} color="#108a82" />;
}
export function AttachmentField({ value, onChange, section, field, photo }: {
  value: string[]; onChange: (value: string[]) => void; section: string; field: string; photo: boolean;
}) {
  const { ensureBooking, horseId } = useIntake();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function add() {
    if (busy) return;
    setBusy(true); setError('');
    try {
      // Open the browser's file chooser directly from the user gesture.
      const picked = photo
        ? await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.85 })
        : await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], copyToCacheDirectory: true });
      if (picked.canceled || !picked.assets?.length) return;
      const asset = picked.assets[0];
      const id = await ensureBooking();
      const token = await getOrMintToken();
      if (!token) throw new Error('Meld je opnieuw aan om een bijlage toe te voegen.');
      const body = new FormData();
      body.append('horse_id', horseId); body.append('section', section); body.append('field', field);
      const file = 'file' in asset ? asset.file : undefined;
      const name = ('name' in asset ? asset.name : asset.fileName) || (photo ? 'foto.jpg' : 'document.pdf');
      const mime = asset.mimeType || (photo ? 'image/jpeg' : 'application/pdf');
      if (Platform.OS === 'web') body.append('file', file instanceof File ? file : await (await fetch(asset.uri)).blob(), name);
      else body.append('file', { uri: asset.uri, name, type: mime } as unknown as Blob);
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 120000);
      try {
        const response = await fetch(`${getApiBaseUrl()}/api/intakes/${id}/attachments`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body, signal: controller.signal });
        const data = await response.json();
        if (!response.ok) throw new Error(data.errors?.file?.[0] || data.message || 'Uploaden mislukt.');
        onChange([...value.filter(item => item.startsWith('attachment:')), data.value]);
      } finally { clearTimeout(timeout); }
    } catch (error) { setError(error instanceof Error && error.name !== 'AbortError' ? error.message : 'Uploaden mislukt. Controleer je verbinding en probeer opnieuw.'); }
    finally { setBusy(false); }
  }
  return <View>
    <View className="mb-2 flex-row flex-wrap gap-3">
      {value.map((reference, index) => <View key={`${reference}-${index}`} className="max-w-[160px] gap-2 rounded-xl bg-white p-3">
        {photo && reference.startsWith('attachment:') ? <Photo reference={reference} /> : <FileText size={22} color="#108a82" />}
        <Text className="text-xs text-ink">{reference.startsWith('attachment:') ? fileName(reference) : 'Oude testbijlage: voeg opnieuw toe'}</Text>
        <Pressable disabled={busy} accessibilityLabel="Bijlage verwijderen" onPress={() => onChange(value.filter((_, i) => i !== index))}><Trash2 size={18} color="#9b3f3f" /></Pressable>
      </View>)}
    </View>
    <Pressable accessibilityRole="button" disabled={busy} onPress={() => void add()} className="flex-row items-center justify-center gap-2 rounded-xl border border-dashed border-ink-15 bg-white p-4">
      {busy ? <ActivityIndicator color="#108a82"/> : photo ? <Camera size={20} color="#108a82"/> : <FileText size={20} color="#108a82"/>}
      <Text className="font-semi text-mint-700">{busy ? 'Uploaden…' : photo ? 'Foto toevoegen' : 'Document toevoegen'}</Text>
    </Pressable>
    <Text className="mt-1 text-xs text-ink-50">{photo ? 'JPG, PNG of WebP' : 'PDF, JPG, PNG of WebP'} · maximaal 15 MB · internet nodig</Text>
    {!!error && <Text accessibilityRole="alert" className="mt-2 text-sm text-red-700">{error}</Text>}
  </View>;
}

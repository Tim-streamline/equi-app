import { useEffect, useRef, useState } from 'react';
import { View, Text, Pressable, ActivityIndicator } from 'react-native';
import type * as ImagePicker from 'expo-image-picker';
import { launchImageLibraryAsync, releasePickedImages } from '@/lib/intake/attachments/image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { Camera, FileText, Plus, Trash2 } from 'lucide-react-native';
import { useDb } from '@/db/provider';
import { accountSession } from '@/lib/account-session';
import { attachmentStorage, saveAttachment } from '@/lib/intake/attachments/storage';
import { onAttachmentsChanged } from '@/lib/intake/attachments/events';
import { validatePhotoDimensions } from '@/lib/intake/attachments/photo-dimensions';
import type { LocalAttachment } from '@/lib/intake/attachments/types';
import { Photo } from './AttachmentPhoto';
import { useIntake } from '@/lib/intake/store';
import { registerRefreshProtection, refreshProtectionChanged } from '@/lib/refresh-safety';

export { Photo } from './AttachmentPhoto';

function fileName(reference: string) {
  try { return decodeURIComponent(reference.split(':').slice(2).join(':')) || 'Bijlage'; } catch { return 'Bijlage'; }
}
function useLocalAttachment(reference: string) {
  const { currentUserId } = useDb();
  const [record, setRecord] = useState<Pick<LocalAttachment, 'uploaded' | 'error'> | null | undefined>(undefined);
  useEffect(() => {
    let active = true;
    let request = 0;
    setRecord(undefined);
    const refresh = () => {
      const version = ++request;
      if (currentUserId) void attachmentStorage.get(currentUserId, reference.split(':')[1])
        .then(value => { if (active && version === request) setRecord(value ? { uploaded: value.uploaded, error: value.error } : null); })
        .catch(() => { if (active && version === request) setRecord(null); });
      else setRecord(null);
    };
    refresh();
    const unsubscribe = onAttachmentsChanged(change => {
      if (change.userId === currentUserId && change.id === reference.split(':')[1]) refresh();
    });
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
type PickedAsset = ImagePicker.ImagePickerAsset | DocumentPicker.DocumentPickerAsset;
type PhotoSlot = { id: string; label: string; field: string };
export function AttachmentField({ value, onChange, section, field, photo, max, renderMetadata, slots, onSlotAdded, photoDesign = false, compactUpload = false }: {
  slots?: PhotoSlot[]; onSlotAdded?: (reference: string, slotId: string) => void;
  photoDesign?: boolean; compactUpload?: boolean;
  max?: number; renderMetadata?: (reference: string) => React.ReactNode;
  value: string[]; onChange: (value: string[]) => void; section: string; field: string; photo: boolean;
}) {
  const { ensureBooking, horseId, state, submitting } = useIntake();
  const readOnly = !!state.submittedAt || submitting;
  const readOnlyRef = useRef(readOnly);
  readOnlyRef.current = readOnly;
  const { currentUserId } = useDb();
  const latest = useRef({ value, onChange, slots, onSlotAdded, max });
  latest.current = { value, onChange, slots, onSlotAdded, max };
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  const bloodTest = section === 'klacht' && field === 'bloedonderzoek';
  const count = (refs: string[]) => refs.filter(item => item.startsWith('attachment:')).length;
  const remaining = () => latest.current.slots?.length ?? Math.max(0, (latest.current.max ?? (bloodTest ? 5 : Infinity)) - count(latest.current.value));
  const limitReached = remaining() === 0;
  const singlePhoto = photo && max === 1 && !slots;
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [error, setError] = useState('');
  const [pending, setPending] = useState<{ assets: PickedAsset[]; assignments: string[]; revision: number } | null>(null);
  const pendingRef = useRef(pending);
  pendingRef.current = pending;
  useEffect(() => () => { releasePickedImages(pendingRef.current?.assets ?? []); }, []);
  function discardPending() {
    releasePickedImages(pendingRef.current?.assets ?? []);
    pendingRef.current = null;
    setPending(null);
  }
  useEffect(() => registerRefreshProtection({
    reason: () => busyRef.current ? 'Rond het kiezen of opslaan van je bestand eerst af.'
      : pendingRef.current ? 'Wijs je gekozen foto’s toe en sla ze op, of annuleer de selectie.' : null,
  }), []);
  useEffect(() => { refreshProtectionChanged(); }, [busy, pending]);
  const checkSession = (revision: number) => {
    if (readOnlyRef.current || !mounted.current || revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
  };
  const message = (error: unknown) => error instanceof Error && error.name !== 'AbortError' ? error.message : 'Opslaan op dit apparaat mislukt. Probeer opnieuw.';
  async function saveAssets(assets: PickedAsset[], revision: number, assignments?: string[]) {
    checkSession(revision);
    const id = await ensureBooking();
    checkSession(revision);
    if (!currentUserId) throw new Error('Meld je opnieuw aan om een bijlage toe te voegen.');
    const errors: string[] = [];
    for (const [index, asset] of assets.entries()) {
      checkSession(revision);
      const target = assignments ? latest.current.slots?.find(slot => slot.id === assignments[index]) : undefined;
      if (assignments && !target) { errors.push('Dit aanzicht is inmiddels ingevuld. Kies de foto opnieuw voor een vrij aanzicht.'); continue; }
      if (!assignments && remaining() === 0) { errors.push('Het maximum aantal bestanden is bereikt. Overige bestanden zijn niet toegevoegd.'); break; }
      const originalName = ('name' in asset ? asset.name : asset.fileName) || (photo ? 'foto.jpg' : 'document.pdf');
      const name = originalName.split(/[\\/]/).pop()!.slice(0, 255);
      try {
        if (photo && 'width' in asset && 'height' in asset) validatePhotoDimensions(asset);
        const size = 'size' in asset ? asset.size : 'fileSize' in asset ? asset.fileSize : undefined;
        if (size && size > 15 * 1024 * 1024) throw new Error('Het bestand is te groot. Maximaal 15 MB per bestand.');
        const file = 'file' in asset ? asset.file : undefined;
        const mime = asset.mimeType || (photo ? 'image/jpeg' : 'application/pdf');
        const allowed = photo ? ['image/jpeg', 'image/png', 'image/webp'] : ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!allowed.includes(mime)) throw new Error(photo ? 'Kies een JPG-, PNG- of WebP-foto.' : 'Kies een PDF-, JPG-, PNG- of WebP-bestand.');
        const attachmentId = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID()
          : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
        await saveAttachment({ id: attachmentId, userId: currentUserId, bookingId: id, horseId, section, field: target?.field ?? field, name, mime, uploaded: false },
          { uri: asset.uri, file, name, mime });
        checkSession(revision);
        if (target && !latest.current.slots?.some(slot => slot.id === target.id)) throw new Error('Dit aanzicht is inmiddels ingevuld.');
        if (!target && remaining() === 0) throw new Error('Het maximum aantal bestanden is bereikt.');
        const reference = `attachment:${attachmentId}:${encodeURIComponent(name)}`;
        // Publish every durable file immediately, including when a later file fails.
        if (target) latest.current.onSlotAdded?.(reference, target.id);
        else {
          const next = [...latest.current.value.filter(item => item.startsWith('attachment:')), reference];
          latest.current.value = next;
          latest.current.onChange(next);
        }
      } catch (error) {
        checkSession(revision);
        errors.push(`${name}: ${message(error)}`);
      }
    }
    setError(errors.join('\n'));
  }
  async function add() {
    if (busyRef.current || remaining() === 0 || readOnlyRef.current) return;
    busyRef.current = true; setBusy(true); setError('');
    const revision = accountSession.revision;
    let pickedAssets: PickedAsset[] = [];
    let keepSelection = false;
    try {
      // Open the browser's file chooser directly from the user gesture.
      const available = remaining();
      const picked = photo
        ? await launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.85,
          allowsMultipleSelection: available > 1, selectionLimit: Number.isFinite(available) ? available : 0 })
        : await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], copyToCacheDirectory: true });
      if (picked.canceled || !picked.assets?.length) return;
      pickedAssets = picked.assets;
      checkSession(revision);
      if (slots) {
        for (const asset of picked.assets) {
          if (photo && 'width' in asset && 'height' in asset) validatePhotoDimensions(asset);
        }
        // Browsers cannot enforce selectionLimit. Do not silently discard excess photos.
        if (picked.assets.length > remaining()) throw new Error(`Kies maximaal ${remaining()} foto’s voor de vrije aanzichten.`);
        const selection = { assets: picked.assets, assignments: picked.assets.map(() => ''), revision };
        pendingRef.current = selection;
        setPending(selection);
        keepSelection = true;
      } else await saveAssets(picked.assets, revision);
    } catch (error) { if (mounted.current) setError(message(error)); }
    finally {
      if (!keepSelection) releasePickedImages(pickedAssets);
      busyRef.current = false; if (mounted.current) setBusy(false);
    }
  }
  async function savePending() {
    if (!pending || busyRef.current) return;
    const assigned = pending.assignments;
    if (assigned.some(id => !id || !slots?.some(slot => slot.id === id)) || new Set(assigned).size !== assigned.length) return;
    busyRef.current = true; setBusy(true); setError('');
    try { await saveAssets(pending.assets, pending.revision, assigned); discardPending(); }
    catch (error) { if (mounted.current) { setError(message(error)); discardPending(); } }
    finally { busyRef.current = false; if (mounted.current) setBusy(false); }
  }
  return <View>
    <View className="mb-2 flex-row flex-wrap gap-3">
      {!slots && value.map((reference, index) => <View key={`${reference}-${index}`} style={singlePhoto ? { width: '100%' } : renderMetadata ? { width: 180 } : undefined} className={`${singlePhoto ? '' : 'max-w-[180px]'} gap-2 rounded-xl bg-white p-3`}>
        {photo && reference.startsWith('attachment:') ? <Photo reference={reference} expanded={singlePhoto} /> : <FileText size={22} color="#108a82" />}
        <Text className="text-xs text-ink">{reference.startsWith('attachment:') ? fileName(reference) : 'Oude testbijlage: voeg opnieuw toe'}</Text>
        {reference.startsWith('attachment:') && <UploadStatus reference={reference} />}
        {renderMetadata?.(reference)}
        {!readOnly && <Pressable disabled={busy} accessibilityRole={singlePhoto ? 'button' : undefined} accessibilityLabel="Bijlage verwijderen"
          onPress={() => { setError(''); onChange(value.filter((_, i) => i !== index)); }}
          className={singlePhoto ? 'min-h-[44px] flex-row items-center justify-center gap-2 self-start rounded-lg border border-red-200 px-3 py-2' : 'self-start p-2'}>
          <Trash2 size={18} color="#9b3f3f" />
          {singlePhoto && <Text className="font-semi text-[14px] text-[#9b3f3f]">Verwijderen</Text>}
        </Pressable>}
      </View>)}
    </View>
    {!readOnly && !pending && ((!photo && !slots) || !limitReached) && <Pressable accessibilityRole="button" disabled={busy || limitReached} onPress={() => void add()}
      className={photo && photoDesign
        ? `items-center justify-center rounded-[20px] border border-dashed border-[#A8DCDA] bg-[#EAF9F7] ${compactUpload ? 'min-h-[115px] p-4' : 'min-h-[175px] p-6'}`
        : 'flex-row items-center justify-center gap-2 rounded-xl border border-dashed border-ink-15 bg-white p-4'}>
      {photo && photoDesign ? <>
        <View className={`items-center justify-center rounded-full bg-mint-500 shadow-sm ${compactUpload ? 'h-10 w-10' : 'h-13 w-13'}`}>
          {busy ? <ActivityIndicator color="white" /> : <Plus size={compactUpload ? 21 : 25} color="white" />}
        </View>
        <Text className="mt-3 font-semi text-[15px] text-[#174B49]">{busy ? 'Toevoegen…' : remaining() > 1 ? 'Foto’s toevoegen' : 'Foto toevoegen'}</Text>
        {!!max && !slots && <Text className="mt-1 text-[12px] text-ink-50">Maximaal {max} {max === 1 ? 'foto' : 'foto’s'}</Text>}
      </> : <>
        {busy ? <ActivityIndicator color="#108a82"/> : photo ? <Camera size={20} color="#108a82"/> : <FileText size={20} color="#108a82"/>}
        <Text className="font-semi text-mint-700">{busy ? (photo ? 'Toevoegen…' : 'Opslaan…') : photo ? (remaining() > 1 ? 'Foto’s toevoegen' : 'Foto toevoegen') : bloodTest ? 'Bloeduitslag toevoegen' : 'Document toevoegen'}</Text>
      </>}
    </Pressable>}
    {!readOnly && pending && <View className="gap-3">
      <Text className="text-sm text-ink">Kies het aanzicht voor iedere foto.</Text>
      {pending.assets.map((asset, index) => <View key={`${asset.uri}-${index}`} className="gap-2 rounded-xl border border-ink-15 p-3">
        <Photo reference={`selected::Geselecteerde foto ${index + 1}`} asset={asset} expanded />
        {slots?.map(slot => {
          const selected = pending.assignments[index] === slot.id;
          const disabled = busy || pending.assignments.some((id, i) => i !== index && id === slot.id);
          return <Pressable key={slot.id} accessibilityRole="radio" accessibilityState={{ checked: selected, disabled }} disabled={disabled}
            onPress={() => setPending(current => current && ({ ...current, assignments: current.assignments.map((id, i) => i === index ? (id === slot.id ? '' : slot.id) : id) }))}
            className="rounded-lg border border-ink-15 p-3">
            <Text className={disabled ? 'text-ink-50' : 'text-mint-700'}>{selected ? '● ' : '○ '}{slot.label}</Text>
          </Pressable>;
        })}
      </View>)}
      <Pressable accessibilityRole="button" disabled={busy || pending.assignments.some(id => !slots?.some(slot => slot.id === id))}
        onPress={() => void savePending()} className="rounded-xl border border-ink-15 p-4">
        <Text className="font-semi text-mint-700">{busy ? 'Toevoegen…' : 'Foto’s opslaan'}</Text>
      </Pressable>
      <Pressable accessibilityRole="button" disabled={busy} onPress={discardPending} className="p-3"><Text className="text-ink">Annuleren</Text></Pressable>
    </View>}
    {bloodTest && <Text className="mt-1 text-xs text-ink-50">Maximaal 5 bestanden</Text>}
    {!!error && <Text accessibilityRole="alert" className="mt-2 text-sm text-red-700">{error}</Text>}
  </View>;
}

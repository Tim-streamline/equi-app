import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, Modal, Platform, Pressable, Text, View } from 'react-native';
import { Camera, Check, CircleAlert, Upload, X } from 'lucide-react-native';
import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { accountSession } from '@/lib/account-session';
import { C, RoundButton, ScannerButton, s } from './ScannerUI';

export type PhotoPhase = 'empty' | 'selecting' | 'selected' | 'uploading' | 'blurry' | 'error';

export function ScannerPhotoCard({ title, detail, required = false, uri, submissionId, photoId, phase = uri || photoId ? 'selected' : 'empty', progress,
  onCamera, onUpload, onReplace, onRemove, onRetry, onUseAnyway, disabled = false }: {
  title: string; detail: string; required?: boolean; uri?: string; submissionId?: string; photoId?: string; phase?: PhotoPhase; progress?: number;
  onCamera?: () => void; onUpload?: () => void; onReplace?: () => void; onRemove?: () => void;
  onRetry?: () => void; onUseAnyway?: () => void; disabled?: boolean;
}) {
  const busy = phase === 'uploading' || phase === 'selecting';
  const warning = phase === 'blurry' || phase === 'error';
  const color = phase === 'error' ? '#8C3625' : phase === 'blurry' ? '#6E4B12' : C.teal;
  const percent = progress == null || !Number.isFinite(progress) ? null : Math.min(100, Math.max(0, progress));
  return <View style={[s.card, { borderRadius: 20, padding: 16, gap: 14 }]}>
    <View style={{ flexDirection: 'row', gap: 10, alignItems: 'flex-start' }}>
      <View style={{ flex: 1 }}><Text style={s.rowTitle}>{title}</Text><Text style={[s.small, { marginTop: 2, lineHeight: 18 }]}>{detail}</Text></View>
      {required && <View style={{ borderRadius: 20, backgroundColor: '#F4EFE7', paddingHorizontal: 9, paddingVertical: 3 }}><Text style={[s.small, { color: C.ink, fontSize: 12, fontFamily: 'SourceSans3_700Bold' }]}>Verplicht</Text></View>}
      {!required && onRemove && <RoundButton label={`${title} verwijderen`} onPress={onRemove}><X size={18} color={C.muted} /></RoundButton>}
    </View>
    {phase === 'empty' ? <View style={{ flexDirection: 'row', gap: 8 }}>
      <View style={{ flex: 1 }}><ScannerButton title="Camera" variant="light" disabled={disabled || !onCamera} icon={<Camera size={19} color={C.deep} />} onPress={onCamera} /></View>
      <View style={{ flex: 1 }}><ScannerButton title="Uploaden" variant="outline" disabled={disabled || !onUpload} icon={<Upload size={18} color={C.ink} />} onPress={onUpload} /></View>
    </View> : <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
      <ScannerPhotoPreview title={title} uri={uri} submissionId={submissionId} photoId={photoId} />
      <View style={{ flex: 1, gap: 4 }}>
        <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center' }}>
          {warning ? <CircleAlert size={18} color={color} /> : !busy && <Check size={18} color={color} />}
          <Text accessibilityLiveRegion="polite" style={[s.body, { fontFamily: 'SourceSans3_600SemiBold', color }]}>
            {phase === 'uploading' ? `Uploaden…${percent === null ? '' : ` ${Math.round(percent)}%`}` : phase === 'selecting' ? 'Foto openen…' : phase === 'blurry' ? 'Foto is onscherp' : phase === 'error' ? 'Uploaden mislukt' : 'Foto geselecteerd'}
          </Text>
        </View>
        {phase === 'uploading' && <View accessibilityRole="progressbar" accessibilityValue={percent === null ? undefined : { min: 0, max: 100, now: percent }} style={{ height: 6, borderRadius: 6, backgroundColor: C.border, overflow: 'hidden' }}>{percent !== null && <View style={{ height: '100%', width: `${percent}%`, backgroundColor: C.mint, borderRadius: 6 }} />}</View>}
        {phase === 'blurry' && <Text style={s.small}>De tekst is niet goed leesbaar.</Text>}
        {phase === 'error' && <Text style={s.small}>Probeer het opnieuw of kies een andere foto.</Text>}
        {phase === 'selected' && <Pressable accessibilityRole="button" disabled={disabled || !onReplace} onPress={onReplace} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={[s.small, { fontSize: 14, color: C.teal, fontFamily: 'SourceSans3_700Bold' }]}>Vervangen</Text></Pressable>}
      </View>
      {required && onRemove && !busy && <RoundButton label={`${title} verwijderen`} onPress={onRemove}><X size={18} color={C.muted} /></RoundButton>}
    </View>}
    {phase === 'blurry' && <View style={{ flexDirection: 'row', gap: 8 }}><View style={{ flex: 1.3 }}><ScannerButton title="Opnieuw maken" disabled={!onCamera} onPress={onCamera} /></View><View style={{ flex: 1 }}><ScannerButton title="Toch gebruiken" variant="outline" disabled={!onUseAnyway} onPress={onUseAnyway} /></View></View>}
    {phase === 'error' && <ScannerButton title="Opnieuw proberen" disabled={!onRetry} onPress={onRetry} />}
  </View>;
}

function ScannerPhotoPreview({ title, uri, submissionId, photoId }: { title: string; uri?: string; submissionId?: string; photoId?: string }) {
  const [remote, setRemote] = useState<{ key: string; source: { uri: string; headers?: Record<string, string> } }>();
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const key = `${submissionId}:${photoId}`;
  useEffect(() => {
    let active = true;
    let objectUrl: string | undefined;
    const controller = new AbortController();
    const revision = accountSession.revision;
    setFailed(false);
    setRemote(undefined);
    if (!uri && submissionId && photoId) void (async () => {
      const url = `${getApiBaseUrl()}/api/feed-scanner/submissions/${encodeURIComponent(submissionId)}/photos/${encodeURIComponent(photoId)}`;
      let token = await getOrMintToken(controller.signal, attempt > 0);
      if (!token || !active || revision !== accountSession.revision) return;
      let headers = { Authorization: `Bearer ${token}` };
      if (Platform.OS !== 'web') {
        setRemote({ key, source: { uri: url, headers } });
        return;
      }
      let response = await fetch(url, { headers, signal: controller.signal, cache: 'no-store' });
      if (response.status === 401) {
        token = await getOrMintToken(controller.signal, true);
        if (!token || !active || revision !== accountSession.revision) return;
        headers = { Authorization: `Bearer ${token}` };
        response = await fetch(url, { headers, signal: controller.signal, cache: 'no-store' });
      }
      if (!response.ok) throw new Error('Photo unavailable');
      const blob = await response.blob();
      if (!active || revision !== accountSession.revision) return;
      objectUrl = URL.createObjectURL(blob);
      setRemote({ key, source: { uri: objectUrl } });
    })().catch(() => { if (active && revision === accountSession.revision) setFailed(true); });
    return () => { active = false; controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [uri, submissionId, photoId, key, attempt]);
  const source = uri ? { uri } : remote?.key === key ? remote.source : undefined;
  const style = { height: 96, width: 96, borderRadius: 12, backgroundColor: '#F4EFE7' };
  return source && !failed ? <Image key={`${uri ?? key}:${attempt}`} accessibilityLabel={`Geselecteerde foto: ${title}`} source={source} resizeMode="contain" style={style} onError={() => setFailed(true)} />
    : <View style={[style, { alignItems: 'center', justifyContent: 'center', padding: 6 }]}>
      {failed ? <Pressable accessibilityRole="button" accessibilityLabel={`Preview opnieuw laden: ${title}`} onPress={() => setAttempt(value => value + 1)}><Text style={[s.small, { textAlign: 'center', fontSize: 12 }]}>Foto niet geladen. Tik om opnieuw te proberen.</Text></Pressable>
        : uri || photoId ? <ActivityIndicator color={C.teal} accessibilityLabel="Foto laden" /> : <Text style={s.small}>Foto</Text>}
    </View>;
}

export function PhotoReplacementSheet({ visible, onClose, onCamera, onUpload }: {
  visible: boolean; onClose: () => void; onCamera: () => void; onUpload: () => void;
}) {
  return <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
    <View style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(13,42,42,0.28)' }}>
      <Pressable accessibilityRole="button" accessibilityLabel="Foto vervangen sluiten" className="active:opacity-100" style={{ flex: 1 }} onPress={onClose} />
      <View accessibilityViewIsModal style={{ backgroundColor: C.canvas, borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 20, paddingBottom: 34, gap: 12 }}>
        <Text style={s.heading}>Foto vervangen</Text>
        <ScannerButton title="Nieuwe foto maken" variant="outline" icon={<Camera size={20} color={C.teal} />} onPress={onCamera} />
        <ScannerButton title="Afbeelding uploaden" variant="outline" icon={<Upload size={20} color={C.teal} />} onPress={onUpload} />
        <ScannerButton title="Annuleren" variant="text" onPress={onClose} />
      </View>
    </View>
  </Modal>;
}

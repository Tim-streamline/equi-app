import { Image, Modal, Pressable, Text, View } from 'react-native';
import { Camera, Check, CircleAlert, FileImage, Upload, X } from 'lucide-react-native';
import { C, RoundButton, ScannerButton, s } from './ScannerUI';

export type PhotoPhase = 'empty' | 'selecting' | 'selected' | 'uploading' | 'blurry' | 'error';

export function ScannerPhotoCard({ title, detail, required = false, uri, phase = uri ? 'selected' : 'empty', progress,
  onCamera, onUpload, onReplace, onRemove, onRetry, onUseAnyway, disabled = false }: {
  title: string; detail: string; required?: boolean; uri?: string; phase?: PhotoPhase; progress?: number;
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
      {uri ? <Image accessibilityLabel={`Geselecteerde foto: ${title}`} source={{ uri }} resizeMode="contain" style={{ height: 64, width: 64, borderRadius: 12, backgroundColor: '#F4EFE7' }} />
        : <View style={{ height: 64, width: 64, borderRadius: 12, backgroundColor: '#F4EFE7', alignItems: 'center', justifyContent: 'center' }}><FileImage size={28} color={C.muted} /></View>}
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

export function PhotoReplacementSheet({ visible, onClose, onCamera, onUpload }: {
  visible: boolean; onClose: () => void; onCamera: () => void; onUpload: () => void;
}) {
  return <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
    <View style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(13,42,42,0.28)' }}>
      <Pressable accessibilityRole="button" accessibilityLabel="Foto vervangen sluiten" style={{ flex: 1 }} onPress={onClose} />
      <View accessibilityViewIsModal style={{ backgroundColor: C.canvas, borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 20, paddingBottom: 34, gap: 12 }}>
        <Text style={s.heading}>Foto vervangen</Text>
        <ScannerButton title="Nieuwe foto maken" variant="outline" icon={<Camera size={20} color={C.teal} />} onPress={onCamera} />
        <ScannerButton title="Afbeelding uploaden" variant="outline" icon={<Upload size={20} color={C.teal} />} onPress={onUpload} />
        <ScannerButton title="Annuleren" variant="text" onPress={onClose} />
      </View>
    </View>
  </Modal>;
}

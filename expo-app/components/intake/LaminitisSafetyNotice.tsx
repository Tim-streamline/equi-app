import { useEffect, useRef, useState } from 'react';
import { Modal, Pressable, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Button } from '@/components/ui/Button';
import { useIntake } from '@/lib/intake/store';
import { useIntakeSchema } from '@/lib/intake/schema-provider';
import { laminitisNotice, acuteChoices, RECOVERY_FIELDS } from '@/lib/intake/laminitis';

export function LaminitisSafetyNotice({ popup = false, recovery = false }: { popup?: boolean; recovery?: boolean }) {
  const { state, laminitisBlocked, laminitisSafety, confirmLaminitisRecovery } = useIntake();
  const { schema } = useIntakeSchema();
  const notice = laminitisNotice(schema, laminitisSafety);
  const [visible, setVisible] = useState(false);
  const shown = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [declarations, setDeclarations] = useState<Record<string, string>>({});
  useEffect(() => { setDeclarations({}); }, [laminitisSafety?.episode_id]);
  useEffect(() => {
    const acute = acuteChoices(schema).includes(String(state.answers.medisch?.hoefbevangenheid));
    if (popup && acute && laminitisBlocked && !shown.current) { shown.current = true; setVisible(true); }
    if (!acute) shown.current = false;
  }, [popup, state.answers.medisch?.hoefbevangenheid, laminitisBlocked, schema]);
  if (!laminitisBlocked) return null;
  const recoveryKeys = state.submittedAt && laminitisSafety?.kind === 'unresolved' ? [...RECOVERY_FIELDS, 'hoefbevangenheid-hersteld'] : RECOVERY_FIELDS;
  const ready = recoveryKeys.every(key => (state.submittedAt ? declarations[key] : state.answers.medisch?.[key]) === 'Ja');
  return <>
    <View accessibilityRole="alert" className="mb-4 rounded-2xl border border-danger/30 bg-white p-4">
      <Text className="font-bold text-[16px] text-danger">{notice?.label ?? 'Herstelstatus bijwerken'}</Text>
      <Text className="mt-2 text-[14px] leading-[21px] text-ink">{notice?.hint}</Text>
      <Text className="mt-2 text-[14px] leading-[21px] text-ink">Je antwoorden blijven opgeslagen. Intake versturen en Plus-protocol opstellen zijn geblokkeerd totdat de herstelstatus is bevestigd.</Text>
      {recovery ? <>
        {!!state.submittedAt && recoveryKeys.map(key => {
          const field = schema.find(s => s.id === 'medisch')?.fields.find(f => f.id === key);
          return <View key={key} className="mt-4"><Text className="font-bold text-ink">{field?.label}</Text><View className="mt-2 flex-row gap-2">{(field?.options ?? []).map(option => <Pressable key={option} accessibilityRole="radio" accessibilityState={{ checked: declarations[key] === option }} onPress={() => setDeclarations(prev => ({ ...prev, [key]: option }))} className={`rounded-xl border px-4 py-3 ${declarations[key] === option ? 'border-mint-500 bg-mint-50' : 'border-ink-8'}`}><Text>{option}</Text></Pressable>)}</View></View>;
        })}
        <Button className="mt-3" title={busy ? 'Herstelstatus bevestigen…' : 'Herstelstatus bevestigen'} disabled={!ready || busy || !laminitisSafety?.episode_id}
          onPress={async () => { setBusy(true); setError(''); try { await confirmLaminitisRecovery(state.submittedAt ? declarations : undefined); } catch(e) { setError(e instanceof Error ? e.message : 'Bevestigen is niet gelukt.'); } finally { setBusy(false); } }} />
        {!laminitisSafety?.episode_id && <Text className="mt-2 text-sm text-ink-70">De melding wordt gesynchroniseerd. Bevestigen is beschikbaar zodra je online bent en de melding is opgeslagen.</Text>}
        {!!error && <Text className="mt-2 text-danger">{error}</Text>}
      </> : <Pressable accessibilityRole="button" onPress={() => router.push('/intake/section/medisch?field=hoefbevangenheid-herstel-uitleg')} className="mt-3 py-2">
        <Text className="font-bold text-mint-700 underline">Herstelstatus bijwerken</Text>
      </Pressable>}
    </View>
    <Modal visible={visible} transparent animationType="fade" onRequestClose={() => setVisible(false)}>
      <View className="flex-1 items-center justify-center bg-black/50 px-5">
        <View accessibilityViewIsModal className="w-full rounded-3xl bg-white p-6" style={{ maxWidth: 520 }}>
          <Text className="text-[21px] font-bold text-danger">{notice?.label}</Text>
          <Text className="my-4 text-[15px] leading-[23px] text-ink">{notice?.hint}</Text>
          <Button title="Begrepen, verder invullen" onPress={() => setVisible(false)} />
        </View>
      </View>
    </Modal>
  </>;
}

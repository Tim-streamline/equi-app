import { useEffect, useRef, useState } from 'react';
import { Modal, Pressable, Text, View } from 'react-native';
import { Button } from '@/components/ui/Button';
import { useIntake } from '@/lib/intake/store';
import { useIntakeSchema } from '@/lib/intake/schema-provider';
import { laminitisNotice, matches, recoveryRequirements } from '@/lib/intake/laminitis';

export function LaminitisSafetyNotice({ popup = false, recovery = false }: { popup?: boolean; recovery?: boolean }) {
  const { state, setField, laminitisBlocked, laminitisSafety, safetyConfig: config, confirmLaminitisRecovery } = useIntake();
  const { schema } = useIntakeSchema();
  const notice = laminitisNotice(schema, laminitisSafety, config, state.answers);
  const [visible, setVisible] = useState(false);
  const shown = useRef<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [declarations, setDeclarations] = useState<Record<string, string>>({});
  const [expanded, setExpanded] = useState(false);
  useEffect(() => { setDeclarations({}); }, [laminitisSafety?.episode_id]);
  const values = config ? state.answers[config.section] ?? {} : {};
  const trigger = config?.triggers.find(t => t.popup && matches(t.when, values));
  useEffect(() => {
    if (popup && trigger && laminitisBlocked && shown.current !== trigger.id) { shown.current = trigger.id; setVisible(true); }
    if (!trigger) shown.current = null;
  }, [popup, trigger, laminitisBlocked]);
  if (!laminitisBlocked) return null;
  if (!config) return <Text accessibilityRole="alert">Er staat een veiligheidsblokkade open. De instellingen worden gesynchroniseerd.</Text>;
  const requirements = recoveryRequirements(config, state.answers, laminitisSafety);
  const ready = matches(requirements, state.submittedAt ? declarations : values);
  const introduction = schema.flatMap(s => s.fields).find(f => f.id === config.recovery.intro);
  return <>
    <View accessibilityRole="alert" className="mb-4 rounded-2xl border border-danger/30 bg-white p-4">
      <Text className="font-bold text-[16px] text-danger">{notice?.label ?? config.messages.update}</Text>
      <Text className="mt-2 text-[14px] leading-[21px] text-ink">{notice?.hint}</Text>
      <Text className="mt-2 text-[14px] leading-[21px] text-ink">{config.messages.blocked}</Text>
      {recovery || expanded ? <>
        {!!introduction?.hint && <Text className="mt-3 text-ink">{introduction.hint}</Text>}
        {Object.entries(requirements).map(([key, accepted]) => {
          const field = laminitisSafety?.recovery_fields?.find(f => f.id === key) ?? schema.flatMap(s => s.fields).find(f => f.id === key);
          const selected = state.submittedAt ? declarations[key] : values[key];
          return <View key={key} className="mt-4"><Text className="font-bold text-ink">{field?.label ?? key}</Text><View className="mt-2 flex-row flex-wrap gap-2">{(field?.options ?? accepted).map(option => <Pressable key={option} accessibilityRole="radio" accessibilityState={{ checked: selected === option }} onPress={() => state.submittedAt ? setDeclarations(prev => ({ ...prev, [key]: option })) : setField(config.section, key, option)} className={`rounded-xl border px-4 py-3 ${selected === option ? 'border-mint-500 bg-mint-50' : 'border-ink-8'}`}><Text>{option}</Text></Pressable>)}</View></View>;
        })}
        <Button className="mt-3" title={config.messages.confirm} disabled={!ready || busy || !laminitisSafety?.episode_id}
          onPress={async () => { setBusy(true); setError(''); try { await confirmLaminitisRecovery(state.submittedAt ? declarations : undefined); } catch(e) { setError(e instanceof Error ? e.message : 'Bevestigen is niet gelukt.'); } finally { setBusy(false); } }} />
        {!laminitisSafety?.episode_id && <Text className="mt-2 text-sm text-ink-70">{config.messages.pending}</Text>}
        {!!error && <Text className="mt-2 text-danger">{error}</Text>}
      </> : <Pressable accessibilityRole="button" onPress={() => setExpanded(true)} className="mt-3 py-2"><Text className="font-bold text-mint-700 underline">{config.messages.update}</Text></Pressable>}
    </View>
    <Modal visible={visible} transparent animationType="fade" onRequestClose={() => setVisible(false)}>
      <View className="flex-1 items-center justify-center bg-black/50 px-5"><View accessibilityViewIsModal className="w-full rounded-3xl bg-white p-6" style={{ maxWidth: 520 }}>
        <Text className="text-[21px] font-bold text-danger">{notice?.label}</Text>
        <Text className="my-4 text-[15px] leading-[23px] text-ink">{notice?.hint}</Text>
        <Button title={config.messages.acknowledge} onPress={() => setVisible(false)} />
      </View></View>
    </Modal>
  </>;
}

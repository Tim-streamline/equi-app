import { useRef } from 'react';
import { Image, Pressable, Text, View } from 'react-native';
import { IntakeTextInput } from './IntakeScrollView';
import { AttachmentField } from './AttachmentField';
import { useIntake } from '@/lib/intake/store';
import { photoId, photoNaKey, photoNotApplicable, photoReferences, validPhotoYear } from '@/lib/intake/logic';
import type { Field, FieldValue } from '@/lib/intake/schema';
import { getApiBaseUrl } from '@/db/auth';

export function PhotoExample({ field }: { field: Field }) {
  const url = field.photoConfig?.example_url;
  if (!url) return null;
  return <View className="my-3 gap-2">
    <Image source={{ uri: url.startsWith('/') ? `${getApiBaseUrl()}${url}` : url }} resizeMode="contain" style={{ width: '100%', height: 280 }} accessibilityLabel={field.photoConfig?.example_caption ?? field.label} />
    {!!field.photoConfig?.example_caption && <Text className="text-xs text-ink-50">{field.photoConfig.example_caption}</Text>}
  </View>;
}

/** Generic photo controls. Categories, slots and rules come from the editable questionnaire. */
export function ConfiguredPhotoField({ field, sectionId, value, onChange }: {
  field: Field; sectionId: string; value: FieldValue; onChange: (value: string[]) => void;
}) {
  const { state, setField } = useIntake();
  const latest = useRef(value);
  latest.current = value;
  const change = (next: string[]) => { latest.current = next; onChange(next); };
  const config = field.photoConfig ?? {};
  const refs = photoReferences(value);
  const na = photoNotApplicable(field, state.answers, sectionId);
  const metadata = state.answers.__photos ?? {};
  const slots = config.slots ?? [];
  const slotOf = (ref: string) => metadata[`slot-${photoId(ref)}`];
  const unassigned = refs.filter(ref => !slots.some(slot => slot.id === slotOf(ref)));
  const setSlot = (ref: string, slot: string) => setField('__photos', `slot-${photoId(ref)}`, slot);
  const renderYear = config.year_required ? (ref: string) => {
    const key = `year-${photoId(ref)}`, year = String(metadata[key] ?? '');
    return <View className="gap-1">
      <Text className="text-xs text-ink">Jaartal van deze foto *</Text>
      <IntakeTextInput accessibilityLabel="Jaartal van deze foto" keyboardType="number-pad" maxLength={4} value={year}
        placeholder="Bijv. 2023" onChangeText={text => setField('__photos', key, text)} className="rounded-lg border border-ink-15 px-3 py-2 text-ink" />
      <Text className="text-xs text-ink-50">Een schatting is ook goed.</Text>
      {!validPhotoYear(year) && <Text className="text-xs text-danger">Vul een geldig jaartal in.</Text>}
    </View>;
  } : undefined;
  return <View className="gap-3">
    <PhotoExample field={field} />
    {!!config.allow_na && <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: na }}
      onPress={() => setField('__photos', photoNaKey(field, sectionId), na ? '' : 'yes')}
      className="rounded-xl border border-ink-15 bg-white p-3">
      <Text className="text-sm text-ink">{na ? '☑' : '☐'} Niet van toepassing</Text>
    </Pressable>}
    {na ? <Text className="text-sm text-ink-50">Afgehandeld. Je kunt deze keuze later wijzigen. Eerder toegevoegde foto’s blijven bewaard.</Text>
      : slots.length ? <View className="gap-5">
        {slots.map(slot => {
          const selected = refs.filter(ref => slotOf(ref) === slot.id);
          return <View key={slot.id} className="gap-2">
            <Text className="font-semi text-ink">{slot.label}</Text>
            <AttachmentField value={selected} onChange={next => {
              for (const ref of next) setSlot(ref, slot.id);
              change([...photoReferences(latest.current).filter(ref => !selected.includes(ref)), ...next]);
            }} section={sectionId} field={field.id} photo max={1} renderMetadata={renderYear} />
          </View>;
        })}
        {!!unassigned.length && <View className="gap-2">
          <Text className="text-sm text-ink">Eerder toegevoegd — kies het aanzicht voor deze foto’s. Opnieuw uploaden is niet nodig.</Text>
          <AttachmentField value={unassigned} max={0} section={sectionId} field={field.id} photo
            onChange={next => change(photoReferences(latest.current).filter(ref => !unassigned.includes(ref) || next.includes(ref)))}
            renderMetadata={ref => <View className="gap-2">{renderYear?.(ref)}{slots.map(slot => {
              const occupied = refs.some(other => other !== ref && slotOf(other) === slot.id);
              return <Pressable key={slot.id} disabled={occupied} accessibilityRole="button" accessibilityState={{ disabled: occupied }}
                onPress={() => setSlot(ref, slot.id)} className="rounded-lg border border-ink-15 p-2">
                <Text className={occupied ? 'text-ink-50' : 'text-mint-700'}>{slot.label}{occupied ? ' (ingevuld)' : ''}</Text>
              </Pressable>;
            })}</View>} />
        </View>}
      </View> : <AttachmentField value={refs} onChange={change} section={sectionId} field={field.id} photo
        max={config.existing_only ? 0 : config.max} renderMetadata={renderYear} />}
    {!!config.max && !na && <Text className="text-xs text-ink-50">Maximaal {config.max} foto’s.</Text>}
  </View>;
}

import { useEffect, useRef, useState } from 'react';
import { Image, Pressable, Text, View } from 'react-native';
import { IntakeTextInput } from './IntakeScrollView';
import { PhotoInstructions } from './PhotoInstructions';
import { AttachmentField, Photo } from './AttachmentField';
import { useIntake } from '@/lib/intake/store';
import { photoId, photoNaKey, photoNotApplicable, photoReferences, photoSlotReferences, validPhotoYear } from '@/lib/intake/logic';
import type { Field, FieldValue } from '@/lib/intake/schema';
import { getApiBaseUrl } from '@/db/auth';

export function PhotoExample({ field }: { field: Field }) {
  const url = field.photoConfig?.example_url;
  const uri = url?.startsWith('/') ? `${getApiBaseUrl()}${url}` : url;
  const [dimensions, setDimensions] = useState<{ uri: string; aspectRatio: number }>();
  useEffect(() => {
    if (!uri) return;
    let active = true;
    Image.getSize(uri, (width, height) => {
      if (active && width > 0 && height > 0) setDimensions({ uri, aspectRatio: width / height });
    }, () => {});
    return () => { active = false; };
  }, [uri]);
  const aspectRatio = dimensions?.uri === uri ? dimensions?.aspectRatio : undefined;
  if (!url) return null;
  return <View className="my-3 gap-2">
    <Image source={{ uri }} resizeMode="contain" style={{ width: '100%', ...(aspectRatio ? { aspectRatio } : { height: 280 }) }} accessibilityLabel={field.photoConfig?.example_caption ?? field.label} />
    {!!field.photoConfig?.example_caption && <Text className="text-xs text-ink-50">{field.photoConfig.example_caption}</Text>}
    {!!field.photoConfig?.example_after && <Text className="text-[16px] leading-[23px] text-ink-70">{field.photoConfig.example_after}</Text>}
  </View>;
}

/** Generic photo controls. Categories, slots and rules come from the editable questionnaire. */
export function ConfiguredPhotoField({ field, sectionId, value, onChange }: {
  field: Field; sectionId: string; value: FieldValue; onChange: (value: string[]) => void;
}) {
  const { state, setField, submitting } = useIntake();
  const readOnly = !!state.submittedAt || submitting;
  const latest = useRef(value);
  latest.current = value;
  const change = (next: string[]) => { latest.current = next; onChange(next); };
  const config = field.photoConfig ?? {};
  if (config.grouped_hooves) return <GroupedHoofPhotos field={field} sectionId={sectionId} />;
  const refs = photoReferences(value);
  const na = photoNotApplicable(field, state.answers, sectionId);
  const metadata = state.answers.__photos ?? {};
  const slots = config.slots ?? [];
  const fixedHoofViews = ['foto-hoef-lv', 'foto-hoef-rv', 'foto-hoef-la', 'foto-hoef-ra'].includes(field.id);
  const slotOf = (ref: string) => metadata[`slot-${photoId(ref)}`];
  const unassigned = refs.filter(ref => !slots.some(slot => photoSlotReferences(field, slot, value, state.answers, sectionId).includes(ref)));
  const setSlot = (ref: string, slot: string) => setField('__photos', `slot-${photoId(ref)}`, slot);
  const renderYear = config.year_required ? (ref: string) => {
    const key = `year-${photoId(ref)}`, year = String(metadata[key] ?? '');
    return <View className="gap-1">
      <Text className="text-xs text-ink">Jaartal van deze foto *</Text>
      {readOnly ? <Text className="text-sm text-ink">{year}</Text> : <IntakeTextInput accessibilityLabel="Jaartal van deze foto" keyboardType="number-pad" maxLength={4} value={year}
        placeholder="Bijv. 2023" onChangeText={text => setField('__photos', key, text)} className="rounded-lg border border-ink-15 px-3 py-2 text-ink" />}
      <Text className="text-xs text-ink-50">Een schatting is ook goed.</Text>
      {!validPhotoYear(year) && <Text className="text-xs text-danger">Vul een geldig jaartal in.</Text>}
    </View>;
  } : undefined;
  const renderMetadata = config.year_required || config.caption ? (ref: string) => <View className="gap-2">
    {renderYear?.(ref)}
    {!!config.caption && <View className="gap-1">
      <Text className="text-xs text-ink">{config.caption.label}</Text>
      {readOnly ? <Text className="text-sm text-ink">{String(metadata[`caption-${photoId(ref)}`] ?? '')}</Text> : <IntakeTextInput accessibilityLabel={config.caption.label} value={String(metadata[`caption-${photoId(ref)}`] ?? '')}
        placeholder={config.caption.placeholder} onChangeText={text => setField('__photos', `caption-${photoId(ref)}`, text)}
        className="rounded-lg border border-ink-15 px-3 py-2 text-ink" />}
    </View>}
  </View> : undefined;
  return <View className="gap-3">
    <PhotoExample field={field} />
    {!!config.allow_na && !readOnly && <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: na }}
      onPress={() => setField('__photos', photoNaKey(field, sectionId), na ? '' : 'yes')}
      className="rounded-xl border border-ink-15 bg-white p-3">
      <Text className="text-sm text-ink">{na ? '☑' : '☐'} Niet van toepassing</Text>
    </Pressable>}
    {na ? <Text className="text-sm text-ink-50">{readOnly ? 'Niet van toepassing' : 'Afgehandeld. Je kunt deze keuze later wijzigen. Eerder toegevoegde foto’s blijven bewaard.'}</Text>
      : slots.length ? <View className="gap-5">
        {slots.map(slot => {
          const selected = photoSlotReferences(field, slot, value, state.answers, sectionId);
          return <View key={slot.id} className="gap-2">
            <Text className="font-semi text-ink">{slot.label}</Text>
            <AttachmentField value={selected} onChange={next => {
              if (slot.answer_field) {
                if (slot.answer_field === field.id) change(next);
                else setField(sectionId, slot.answer_field, next);
                return;
              }
              for (const ref of next) setSlot(ref, slot.id);
              change([...photoReferences(latest.current).filter(ref => !selected.includes(ref)), ...next]);
            }} section={sectionId} field={slot.answer_field ?? field.id} photo max={1} renderMetadata={renderMetadata} />
          </View>;
        })}
        {!readOnly && !fixedHoofViews && slots.length > 1 && <AttachmentField value={[]} onChange={() => {}} section={sectionId} field={field.id} photo
          slots={slots.filter(slot => !photoSlotReferences(field, slot, value, state.answers, sectionId).length)
            .map(slot => ({ id: slot.id, label: slot.label, field: slot.answer_field ?? field.id }))}
          onSlotAdded={(ref, slotId) => {
            const slot = slots.find(item => item.id === slotId);
            if (!slot) return;
            if (slot.answer_field && slot.answer_field !== field.id) setField(sectionId, slot.answer_field, [ref]);
            else {
              if (!slot.answer_field) setSlot(ref, slot.id);
              change([...photoReferences(latest.current), ref]);
            }
          }} />}
        {!!unassigned.length && <View className="gap-2">
          <Text className="text-sm text-ink">Eerder toegevoegd — kies het aanzicht voor deze foto’s. Opnieuw uploaden is niet nodig.</Text>
          <AttachmentField value={unassigned} max={0} section={sectionId} field={field.id} photo
            onChange={next => change(photoReferences(latest.current).filter(ref => !unassigned.includes(ref) || next.includes(ref)))}
            renderMetadata={ref => <View className="gap-2">{renderMetadata?.(ref)}{slots.filter(slot => !slot.answer_field).map(slot => {
              const occupied = refs.some(other => other !== ref && slotOf(other) === slot.id);
              return <Pressable key={slot.id} disabled={occupied || readOnly} accessibilityRole="button" accessibilityState={{ disabled: occupied }}
                onPress={() => setSlot(ref, slot.id)} className="rounded-lg border border-ink-15 p-2">
                <Text className={occupied ? 'text-ink-50' : 'text-mint-700'}>{slot.label}{occupied ? ' (ingevuld)' : ''}</Text>
              </Pressable>;
            })}</View>} />
        </View>}
      </View> : <AttachmentField value={refs} onChange={change} section={sectionId} field={field.id} photo
        max={config.existing_only ? 0 : config.max} renderMetadata={renderMetadata} />}
    {!!config.max && !na && <Text className="text-xs text-ink-50">Maximaal {config.max} foto’s.</Text>}
  </View>;
}

function GroupedHoofPhotos({ field, sectionId }: { field: Field; sectionId: string }) {
  const { state, setField, submitting } = useIntake();
  const readOnly = !!state.submittedAt || submitting;
  const na = photoNotApplicable(field, state.answers, sectionId);
  const groups = field.photoConfig?.grouped_hooves ?? [];
  const legacy = photoReferences(state.answers[field.answerSection ?? sectionId]?.[field.photoConfig?.legacy_field ?? '']);
  const latest = useRef<Record<string, string[]>>({});
  for (const group of groups) latest.current[group.field] = photoReferences(state.answers[field.answerSection ?? sectionId]?.[group.field]);
  return <View className="gap-3">
    {!readOnly && <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: na }}
      onPress={() => setField('__photos', photoNaKey(field, sectionId), na ? '' : 'yes')}
      className="rounded-xl border border-ink-15 bg-white p-3">
      <Text className="text-sm text-ink">{na ? '☑' : '☐'} Niet van toepassing</Text>
    </Pressable>}
    {na ? <Text className="text-sm text-ink-50">Niet van toepassing</Text> : <>
      <PhotoInstructions field={field} />
      <PhotoExample field={field} />
      {groups.map(group => <View key={group.field} className="gap-3 pt-2">
        <Text className="font-bold text-[16px] text-ink">{group.label}</Text>
        {group.slots.map(slot => {
          const configured = field.photoConfig?.slots?.find(item => item.answer_field === group.field && item.answer_slot === slot.id);
          if (!configured) return null;
          const selected = photoSlotReferences(field, configured, undefined, state.answers, sectionId);
          return <View key={slot.id} className="gap-2">
            <Text className="font-semi text-ink">{slot.label}</Text>
            <AttachmentField value={selected} onChange={next => {
              const current = latest.current[group.field] ?? [];
              const updated = [...current.filter(ref => !selected.includes(ref)), ...next];
              latest.current[group.field] = updated;
              for (const ref of next) setField('__photos', `slot-${photoId(ref)}`, slot.id);
              setField(field.answerSection ?? sectionId, group.field, updated);
            }} section={field.answerSection ?? sectionId} field={group.field} photo max={1} />
          </View>;
        })}
      </View>)}
      {!!legacy.length && <View className="gap-2 pt-2">
        <Text className="font-semi text-ink">Eerder toegevoegde extra hoefbeelden</Text>
        <View className="flex-row flex-wrap gap-3">{legacy.map(ref => <Photo key={ref} reference={ref} />)}</View>
      </View>}
    </>}
  </View>;
}

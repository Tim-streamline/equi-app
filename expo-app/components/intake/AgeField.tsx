import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { IntakeInputFrame, IntakeTextInput as TextInput } from './IntakeScrollView';
import { Field } from '@/lib/intake/schema';
import { ageFromBirthdate } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';

/** Generic paired input: both answer keys and all question copy come from admin. */
export function AgeField({ field, sectionId }: { field: Field; sectionId: string }) {
  const { state, setField } = useIntake();
  const config = field.ageConfig;
  const answers = state.answers[sectionId] ?? {};
  const value = answers[field.id];
  const date = config ? answers[config.date_field] : undefined;
  const dateText = typeof date === 'string' ? date : '';
  const [expanded, setExpanded] = useState(Boolean(dateText));
  const calculated = ageFromBirthdate(dateText);
  useEffect(() => {
    if (calculated !== undefined && value !== calculated) setField(sectionId, field.id, calculated);
  }, [calculated, value, field.id, sectionId, setField]);
  const exact = expanded || Boolean(dateText);
  return <View className="gap-3">
    <IntakeInputFrame className="flex-row items-center rounded-xl border border-ink-8 bg-white">
      <TextInput accessibilityLabel={field.label} value={calculated !== undefined ? String(calculated) : String(value ?? '')}
        editable={!dateText} keyboardType="number-pad" placeholder={field.placeholder}
        onChangeText={text => setField(sectionId, field.id, /^\d+$/.test(text) ? Number(text) : text || undefined)}
        className="flex-1 px-4 py-3.5 font-sans text-[16px] text-ink" />
      <Text className="pr-4 font-semi text-[13px] text-ink-70">{field.unit}</Text>
    </IntakeInputFrame>
    {config && (exact ? <View className="gap-2">
      <Text className="font-semi text-ink">{config.date_label}</Text>
      <IntakeInputFrame className="rounded-xl border border-ink-8 bg-white"><TextInput accessibilityLabel={config.date_label} value={dateText} placeholder="dd-mm-jjjj" keyboardType="numbers-and-punctuation"
        onChangeText={text => setField(sectionId, config.date_field, text || undefined)}
        className="px-4 py-3.5 font-sans text-[16px] text-ink" /></IntakeInputFrame>
      {dateText !== '' && calculated === undefined && <Text accessibilityRole="alert" className="text-red-700">Vul een geldige geboortedatum in die niet in de toekomst ligt.</Text>}
      <Pressable accessibilityRole="button" onPress={() => { setField(sectionId, config.date_field, undefined); setExpanded(false); }}>
        <Text className="py-2 text-teal-600 underline">{config.estimate_label}</Text>
      </Pressable>
    </View> : <Pressable accessibilityRole="button" onPress={() => setExpanded(true)}>
      <Text className="py-2 text-teal-600 underline">{config.toggle_label}</Text>
    </Pressable>)}
  </View>;
}

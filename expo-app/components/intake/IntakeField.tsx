import { PhotoInstructions } from './PhotoInstructions';
// Field renderer for the protocol intake. Dispatches on `field.type` and
// reads/writes the value through the IntakeProvider. Sticking to one parent
// component keeps auto-save behavior uniform across every input type.

import { View, Text, Pressable } from 'react-native';
import { Check, Plus, Trash2 } from 'lucide-react-native';

import { IntakeTextInput as TextInput } from './IntakeScrollView';
import { Field, FieldValue, RepeaterSub } from '@/lib/intake/schema';
import { isFieldRequired, isNoneOption, photoNotApplicable, photoReferences, photoSlotReferences } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';
import { AttachmentField } from './AttachmentField';
import { ConfiguredPhotoField, PhotoExample } from './ConfiguredPhotoField';
import { FieldLabel } from './FieldLabel';
import { AgeField } from './AgeField';

/** Normalize a persisted value to an array — guards against stale answers
 * saved under a different field type (e.g. a radio value left behind when the
 * field became a multi), which previously crashed the renderer on toggle. */
function asArray<T = string>(value: FieldValue): T[] {
  if (Array.isArray(value)) return value as T[];
  if (value == null || value === '') return [];
  return [value as unknown as T];
}

/** Normalize a persisted value to a string for text-like inputs. */
function asText(value: FieldValue): string | undefined {
  if (value == null) return undefined;
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return String(value);
  return undefined;
}

type Props = {
  field: Field;
  /** Section id used to scope the answer mutation. */
  sectionId: string;
  /** Auto-numbered position used for the eyebrow ("01", "02", …). */
  n: number;
  /** Backend-configured mutually exclusive none/not-applicable answers. */
  noneOptions: readonly string[];
};

export function IntakeField({ field, sectionId, n, noneOptions }: Props) {
  const { state, setField } = useIntake();
  const answerSection = field.answerSection ?? sectionId;
  const value = state.answers[answerSection]?.[field.id];
  const set = (next: FieldValue) => {
    setField(answerSection, field.id, next);
  };
  const photoDesign = sectionId === 'intake-fotos' && field.type === 'photo';
  const slots = field.photoConfig?.slots ?? [];
  const photoCount = slots.length
    ? slots.reduce((count, slot) => count + photoSlotReferences(field, slot, value, state.answers, sectionId).length, 0)
    : photoReferences(value).length;
  const photoLimit = slots.length || field.photoConfig?.max;

  if (field.type === 'sectionhead') {
    return (
      <View className="mt-3 mb-2 border-t border-ink-8 pt-3">
        <Text className={field.photoConfig ? "font-bold text-[18px] leading-[24px] text-ink" : "font-semi uppercase text-mint-700 text-[11px] tracking-eyebrow"}>
          {field.label}
        </Text>
        {field.hint && <Text className={field.photoConfig ? "mt-2 text-[16px] leading-[23px] text-ink-70" : "mt-2 text-[13.5px] leading-[20px] text-ink-70"}>{field.hint}</Text>}
        <PhotoInstructions field={field} />
        <PhotoExample field={field} />

      </View>
    );
  }

  return (
    <View className={photoDesign ? '' : 'mb-5'}>
      {photoDesign && <View className="mb-3 flex-row items-center justify-between gap-2">
        <Text className="font-bold text-[11px] tracking-eyebrow text-mint-700">FOTO {String(n).padStart(2, '0')}</Text>
        <View className="rounded-full bg-canvas-2 px-3 py-1">
          <Text className="text-[11px] text-ink-70">{photoNotApplicable(field, state.answers, sectionId) ? 'Niet van toepassing' : `${photoCount}${photoLimit ? ` / ${photoLimit}` : ''} foto’s`}</Text>
        </View>
      </View>}
      <FieldLabel
        n={photoDesign ? 0 : n}
        label={field.label}
        hint={field.hint}
        photo={field.type === 'photo'}
        required={!photoDesign && isFieldRequired(field) && !field.photoConfig?.grouped_hooves}
        link={field.link}
      />
      {field.type === 'photo' && !field.photoConfig?.grouped_hooves && !photoDesign && <PhotoInstructions field={field} />}
      {state.submittedAt && !['photo', 'file'].includes(field.type)
        ? <ReadOnlyAnswer field={field} value={value} answers={state.answers[answerSection] ?? {}} />
        : renderInput(field, value, set, noneOptions, answerSection, photoDesign)}
    </View>
  );
}


function ReadOnlyAnswer({ field, value, answers }: { field: Field; value: FieldValue; answers: Record<string, FieldValue> }) {
  let text = '';
  if (field.type === 'repeater' && Array.isArray(value)) {
    text = value.map(row => (field.sub ?? []).map(sub => {
      const entry = row && typeof row === 'object' ? (row as Record<string, string>)[sub.id] : null;
      return entry ? `${sub.label}: ${entry}` : '';
    }).filter(Boolean).join('\n')).filter(Boolean).join('\n\n');
  } else text = Array.isArray(value) ? value.join(' · ') : value == null ? '' : String(value);
  if (field.unit && text) text += ` ${field.unit}`;
  const birthDate = field.ageConfig && answers[field.ageConfig.date_field];
  if (birthDate) text += `${text ? '\n' : ''}${field.ageConfig!.date_label}: ${birthDate}`;
  return <Text selectable className="rounded-xl bg-white p-3 text-[16px] leading-[23px] text-ink">{text || 'Niet ingevuld'}</Text>;
}

function renderInput(
  field: Field,
  value: FieldValue,
  set: (v: FieldValue) => void,
  noneOptions: readonly string[],
  sectionId: string,
  photoDesign = false,
) {
  switch (field.type) {
    case 'text':
      if (field.lines && field.lines > 1) {
        return (
          <MultiLineTextField
            value={asArray(value)}
            onChange={set}
            lines={field.lines}
            placeholder={field.placeholder}
          />
        );
      }
      return (
        <TextInputField
          value={asText(value)}
          onChange={set}
          unit={field.unit}
          placeholder={field.placeholder}
        />
      );
    case 'textarea':
      return (
        <TextAreaField
          value={asText(value)}
          onChange={set}
          tall={field.tall}
          placeholder={field.placeholder}
        />
      );
    case 'number':
      if (field.ageConfig) return <AgeField field={field} sectionId={sectionId} />;
      return (
        <TextInputField
          value={asText(value)}
          onChange={(v) => set(v === '' ? undefined : Number(v))}
          unit={field.unit}
          placeholder={field.placeholder}
          keyboard="decimal-pad"
        />
      );
    case 'date':
      return (
        <TextInputField
          value={asText(value)}
          onChange={set}
          placeholder="dd-mm-jjjj"
        />
      );
    case 'radio':
      return (
        <RadioField
          options={field.options ?? []}
          value={asText(value)}
          onChange={set}
        />
      );
    case 'multi':
      return (
        <MultiField
          options={field.options ?? []}
          value={asArray(value)}
          onChange={set}
          noneOptions={sectionId === 'gedrag' && field.id === 'gedrag-signalen'
            ? [...noneOptions, 'geen van bovenstaande', 'weet ik niet']
            : noneOptions}
        />
      );
    case 'photo':
      return <ConfiguredPhotoField field={field} sectionId={sectionId} value={value} onChange={set} design={photoDesign} />;
    case 'file':
      return <AttachmentField value={asArray(value)} onChange={set} section={sectionId} field={field.id} photo={false} />;
    case 'repeater':
      return (
        <RepeaterField
          sub={field.sub ?? []}
          firstAddLabel={sectionId === 'voer' && ['huidig-extra', 'historie-extra'].includes(field.id) ? 'Supplement toevoegen' : undefined}
          addLabel={sectionId === 'medisch' && field.id === 'medicatie-ooit-details' ? 'Medicatie toevoegen' : undefined}
          value={asArray<Record<string, string>>(value)}
          onChange={(rows) => set(rows)}
        />
      );
    default:
      return null;
  }
}

/* ---------------------------------------------------------------- TEXT/NUM */

function TextInputField({
  value,
  onChange,
  unit,
  placeholder,
  keyboard,
}: {
  value?: string;
  onChange: (v: string) => void;
  unit?: string;
  placeholder?: string;
  keyboard?: 'default' | 'decimal-pad';
}) {
  return (
    <View className="flex-row items-center rounded-xl border border-ink-8 bg-white">
      <TextInput
        value={value ?? ''}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor="rgba(27,42,42,0.4)"
        keyboardType={keyboard === 'decimal-pad' ? 'decimal-pad' : 'default'}
        className="flex-1 px-4 py-3.5 font-sans text-[15px] text-ink"
        style={keyboard === 'decimal-pad' ? { fontWeight: '600' } : undefined}
      />
      {unit && (
        <Text className="pr-4 font-semi text-[13px] text-ink-50">{unit}</Text>
      )}
    </View>
  );
}

/** N stacked single-line inputs whose values are stored as a string array. */
function MultiLineTextField({
  value,
  onChange,
  lines,
  placeholder,
}: {
  value: string[];
  onChange: (v: string[]) => void;
  lines: number;
  placeholder?: string;
}) {
  const update = (i: number, v: string) => {
    const next = Array.from({ length: lines }, (_, idx) => value[idx] ?? '');
    next[i] = v;
    onChange(next);
  };
  return (
    <View className="gap-2">
      {Array.from({ length: lines }).map((_, i) => (
        <View key={i} className="flex-row items-center rounded-xl border border-ink-8 bg-white">
          <TextInput
            value={value[i] ?? ''}
            onChangeText={(v) => update(i, v)}
            placeholder={placeholder}
            placeholderTextColor="rgba(27,42,42,0.4)"
            className="flex-1 px-4 py-3.5 font-sans text-[15px] text-ink"
          />
        </View>
      ))}
    </View>
  );
}

function TextAreaField({
  value,
  onChange,
  tall,
  placeholder,
}: {
  value?: string;
  onChange: (v: string) => void;
  tall?: boolean;
  placeholder?: string;
}) {
  return (
    <TextInput
      multiline
      numberOfLines={tall ? 12 : 4}
      value={value ?? ''}
      onChangeText={onChange}
      placeholder={placeholder}
      placeholderTextColor="rgba(27,42,42,0.4)"
      className="rounded-xl border border-ink-8 bg-white px-4 py-3.5 font-sans text-[15px] text-ink"
      style={{ minHeight: tall ? 288 : 96, textAlignVertical: 'top' }}
    />
  );
}

/* ---------------------------------------------------------------- RADIO */

function RadioField({
  options,
  value,
  onChange,
}: {
  options: string[];
  value?: string;
  onChange: (v: string) => void;
}) {
  // ≤4 short labels → horizontal pill row; otherwise stacked rows for legibility.
  const compact = options.length <= 4 && options.every((o) => o.length <= 12);
  if (compact) {
    return (
      <View className="flex-row gap-1.5">
        {options.map((o) => {
          const active = value === o;
          return (
            <Pressable
              key={o}
              onPress={() => onChange(o)}
              className={`flex-1 items-center justify-center rounded-xl border py-3 ${
                active
                  ? 'border-mint-500 bg-mint-50'
                  : 'border-ink-8 bg-white'
              }`}
            >
              <Text
                className={`text-center font-semi text-[13px] ${
                  active ? 'text-mint-700' : 'text-ink-70'
                }`}
              >
                {o}
              </Text>
            </Pressable>
          );
        })}
      </View>
    );
  }
  return (
    <View className="gap-2">
      {options.map((o) => {
        const active = value === o;
        return (
          <Pressable
            key={o}
            onPress={() => onChange(o)}
            className={`flex-row items-center gap-3 rounded-xl border px-4 py-3 ${
              active ? 'border-mint-500 bg-mint-50' : 'border-ink-8 bg-white'
            }`}
          >
            <View
              className={`h-5 w-5 items-center justify-center rounded-full border-2 ${
                active ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
              }`}
            >
              {active && <View className="h-2 w-2 rounded-full bg-white" />}
            </View>
            <Text
              className={`flex-1 font-semi text-[14px] ${
                active ? 'text-mint-700' : 'text-ink'
              }`}
            >
              {o}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/* ---------------------------------------------------------------- MULTI */

function MultiField({
  options,
  value,
  onChange,
  noneOptions,
}: {
  options: string[];
  value: string[];
  onChange: (v: string[]) => void;
  noneOptions: readonly string[];
}) {
  const toggle = (o: string) => {
    // Mutually exclusive "none" sentinel ("geen", "Geen van onderstaande",
    // "Nee, nooit", …) — picking it clears everything else; picking any other
    // option clears a previously selected sentinel. Mirrors how the therapist
    // reads the answers.
    if (isNoneOption(o, noneOptions)) {
      onChange(value.includes(o) ? [] : [o]);
      return;
    }
    const filtered = value.filter((v) => !isNoneOption(v, noneOptions));
    onChange(
      filtered.includes(o) ? filtered.filter((v) => v !== o) : [...filtered, o],
    );
  };
  // Short labels → wrap chips; long labels → checkbox rows for readability.
  const useChips = options.every((o) => o.length <= 14);
  if (useChips) {
    return (
      <View className="flex-row flex-wrap gap-1.5">
        {options.map((o) => {
          const active = value.includes(o);
          return (
            <Pressable
              key={o}
              onPress={() => toggle(o)}
              className={`flex-row items-center rounded-pill border px-3.5 py-2 ${
                active
                  ? 'border-mint-500 bg-mint-500'
                  : 'border-ink-8 bg-white'
              }`}
            >
              {active && (
                <Check size={12} color="#fff" strokeWidth={3} style={{ marginRight: 4 }} />
              )}
              <Text
                className={`font-semi text-[13px] ${active ? 'text-white' : 'text-ink-70'}`}
              >
                {o}
              </Text>
            </Pressable>
          );
        })}
      </View>
    );
  }
  return (
    <View className="gap-2">
      {options.map((o) => {
        const active = value.includes(o);
        return (
          <Pressable
            key={o}
            onPress={() => toggle(o)}
            className={`flex-row items-center gap-3 rounded-xl border px-4 py-3 ${
              active ? 'border-mint-500 bg-mint-50' : 'border-ink-8 bg-white'
            }`}
          >
            <View
              className={`h-5 w-5 items-center justify-center rounded-md border-2 ${
                active ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'
              }`}
            >
              {active && <Check size={14} color="#fff" strokeWidth={3} />}
            </View>
            <Text
              className={`flex-1 font-semi text-[14px] ${
                active ? 'text-mint-700' : 'text-ink'
              }`}
            >
              {o}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/* ---------------------------------------------------------------- PHOTO */

function RepeaterField({
  firstAddLabel,
  addLabel,
  sub,
  value,
  onChange,
}: {
  firstAddLabel?: string;
  addLabel?: string;
  sub: RepeaterSub[];
  value: Record<string, string>[];
  onChange: (rows: Record<string, string>[]) => void;
}) {
  const add = () => onChange([...value, {}]);
  const remove = (i: number) => onChange(value.filter((_, idx) => idx !== i));
  const update = (i: number, key: string, v: string) => {
    const next = [...value];
    next[i] = { ...(next[i] ?? {}), [key]: v };
    onChange(next);
  };

  return (
    <View>
      <View className="gap-3">
        {value.map((row, i) => (
          <View
            key={i}
            className="gap-2 rounded-xl border border-ink-8 bg-white p-3"
          >
            <View className="flex-row items-center justify-between">
              <Text className="font-semi text-[11px] uppercase tracking-eyebrow text-ink-50">
                #{i + 1}
              </Text>
              <Pressable onPress={() => remove(i)} hitSlop={8}>
                <Trash2 size={14} color="rgba(27,42,42,0.5)" />
              </Pressable>
            </View>
            {sub.map((s) => (
              <View key={s.id}>
                <Text className="mb-1 text-[11px] font-semi tracking-display text-ink-70">
                  {s.label}
                </Text>
                {s.hint && (
                  <Text className="mb-1.5 text-[11px] leading-[15px] text-ink-50">{s.hint}</Text>
                )}
                {s.type === 'radio' && s.options ? (
                  <View className="flex-row flex-wrap gap-1.5">
                    {s.options.map((o) => {
                      const active = row[s.id] === o;
                      return (
                        <Pressable
                          key={o}
                          onPress={() => update(i, s.id, o)}
                          className={`rounded-pill border px-3 py-1.5 ${
                            active ? 'border-mint-500 bg-mint-500' : 'border-ink-8 bg-canvas'
                          }`}
                        >
                          <Text
                            className={`font-semi text-[12.5px] ${
                              active ? 'text-white' : 'text-ink-70'
                            }`}
                          >
                            {o}
                          </Text>
                        </Pressable>
                      );
                    })}
                  </View>
                ) : (
                  <TextInput
                    value={row[s.id] ?? ''}
                    onChangeText={(v) => update(i, s.id, v)}
                    multiline={s.type === 'textarea'}
                    keyboardType={s.type === 'number' ? 'decimal-pad' : 'default'}
                    placeholderTextColor="rgba(27,42,42,0.4)"
                    className="rounded-lg border border-ink-8 bg-canvas px-3 py-2 font-sans text-[13.5px] text-ink"
                  />
                )}
              </View>
            ))}
          </View>
        ))}
      </View>
      <Pressable
        onPress={add}
        className="mt-3 flex-row items-center justify-center gap-2 rounded-xl border border-dashed border-ink-15 bg-white py-3"
      >
        <Plus size={16} color="#108A82" />
        <Text className="font-semi text-[13px] text-mint-700">
          {addLabel ?? (value.length === 0 ? (firstAddLabel ?? 'Eerste toevoegen') : 'Nog één toevoegen')}
        </Text>
      </Pressable>
    </View>
  );
}

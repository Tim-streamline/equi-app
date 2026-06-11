// Field renderer for the protocol intake. Dispatches on `field.type` and
// reads/writes the value through the IntakeProvider. Sticking to one parent
// component keeps auto-save behavior uniform across every input type.

import { View, Text, TextInput, Pressable, Image } from 'react-native';
import { Check, Camera, Plus, FileText, Trash2 } from 'lucide-react-native';

import { Field, FieldValue, RepeaterSub } from '@/lib/intake/schema';
import { isFieldRequired, isNoneOption } from '@/lib/intake/logic';
import { useIntake } from '@/lib/intake/store';
import { FieldLabel } from './FieldLabel';

/** Bundled example strip shown under the "hoefgerelateerd" upload header. */
const HOOF_EXAMPLE = require('@/assets/images/intake-hoef-voorbeeld.png');

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
};

export function IntakeField({ field, sectionId, n }: Props) {
  const { state, setField } = useIntake();
  const value = state.answers[sectionId]?.[field.id];
  const set = (next: FieldValue) => setField(sectionId, field.id, next);

  if (field.type === 'sectionhead') {
    return (
      <View className="mt-3 mb-2 border-t border-ink-8 pt-3">
        <Text className="font-semi uppercase text-mint-700 text-[11px] tracking-eyebrow">
          {field.label}
        </Text>
        {field.id === 'sec-hoef-upload' && (
          <View className="mt-3">
            <Image
              source={HOOF_EXAMPLE}
              style={{ width: '100%', height: 96, borderRadius: 12, resizeMode: 'cover' }}
            />
            <Text className="mt-1 text-[11px] text-ink-50">
              Voorbeeld: onderzijde, achterzijde, zijaanzicht · bron: David Landreville
            </Text>
          </View>
        )}
      </View>
    );
  }

  return (
    <View className="mb-5">
      <FieldLabel
        n={n}
        label={field.label}
        hint={field.hint}
        required={isFieldRequired(field)}
        link={field.link}
      />
      {renderInput(field, value, set)}
    </View>
  );
}

function renderInput(field: Field, value: FieldValue, set: (v: FieldValue) => void) {
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
        />
      );
    case 'photo':
      return (
        <PhotoField
          value={asArray(value)}
          onChange={set}
        />
      );
    case 'file':
      return (
        <FileField
          value={asArray(value)}
          onChange={set}
        />
      );
    case 'repeater':
      return (
        <RepeaterField
          sub={field.sub ?? []}
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
}: {
  options: string[];
  value: string[];
  onChange: (v: string[]) => void;
}) {
  const toggle = (o: string) => {
    // Mutually exclusive "none" sentinel ("geen", "Geen van onderstaande",
    // "Nee, nooit", …) — picking it clears everything else; picking any other
    // option clears a previously selected sentinel. Mirrors how the therapist
    // reads the answers.
    if (isNoneOption(o)) {
      onChange(value.includes(o) ? [] : [o]);
      return;
    }
    const filtered = value.filter((v) => !isNoneOption(v));
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

function PhotoField({
  value,
  onChange,
}: {
  value: string[];
  onChange: (v: string[]) => void;
}) {
  // Local-only stub: tap = add a placeholder entry so the UI can prove the
  // wiring works. A future PR can replace this with expo-image-picker and
  // an actual upload URL.
  const add = () => onChange([...value, `placeholder-${value.length + 1}`]);
  const remove = (i: number) => onChange(value.filter((_, idx) => idx !== i));
  return (
    <View>
      {value.length > 0 && (
        <View className="mb-2 flex-row flex-wrap gap-2">
          {value.map((p, i) => (
            <View key={i} className="relative">
              <View className="h-16 w-16 items-center justify-center rounded-xl bg-mint-100">
                <Camera size={20} color="#108A82" />
              </View>
              <Pressable
                onPress={() => remove(i)}
                hitSlop={8}
                className="absolute -right-1 -top-1 h-5 w-5 items-center justify-center rounded-full bg-ink"
              >
                <Text className="font-bold text-[10px] text-white">×</Text>
              </Pressable>
            </View>
          ))}
        </View>
      )}
      <Pressable
        onPress={add}
        className="flex-row items-center justify-center gap-2 rounded-xl border border-dashed border-ink-15 bg-white py-4"
      >
        <Camera size={18} color="#108A82" />
        <Text className="font-semi text-[13px] text-mint-700">
          {value.length === 0 ? 'Foto toevoegen' : 'Nog een foto'}
        </Text>
      </Pressable>
    </View>
  );
}

/* ---------------------------------------------------------------- FILE */

function FileField({
  value,
  onChange,
}: {
  value: string[];
  onChange: (v: string[]) => void;
}) {
  // Same local-only treatment as photos — a placeholder label per "upload".
  const add = () => onChange([...value, `document-${value.length + 1}.pdf`]);
  const remove = (i: number) => onChange(value.filter((_, idx) => idx !== i));
  return (
    <View>
      {value.length > 0 && (
        <View className="mb-2 gap-1.5">
          {value.map((name, i) => (
            <View
              key={i}
              className="flex-row items-center gap-3 rounded-xl border border-ink-8 bg-white px-3 py-2.5"
            >
              <FileText size={18} color="#108A82" />
              <Text className="flex-1 font-semi text-[13px] text-ink" numberOfLines={1}>
                {name}
              </Text>
              <Pressable onPress={() => remove(i)} hitSlop={8}>
                <Trash2 size={16} color="rgba(27,42,42,0.5)" />
              </Pressable>
            </View>
          ))}
        </View>
      )}
      <Pressable
        onPress={add}
        className="flex-row items-center justify-center gap-2 rounded-xl border border-dashed border-ink-15 bg-white py-3.5"
      >
        <Plus size={18} color="#108A82" />
        <Text className="font-semi text-[13px] text-mint-700">Document toevoegen</Text>
      </Pressable>
    </View>
  );
}

/* ---------------------------------------------------------------- REPEATER */

function RepeaterField({
  sub,
  value,
  onChange,
}: {
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
          {value.length === 0 ? 'Eerste toevoegen' : 'Nog één toevoegen'}
        </Text>
      </Pressable>
    </View>
  );
}

// Field renderer for the protocol intake. Dispatches on `field.type` and
// reads/writes the value through the IntakeProvider. Sticking to one parent
// component keeps auto-save behavior uniform across every input type.

import { useMemo, useState } from 'react';
import {
  View,
  Text,
  TextInput,
  Pressable,
  PanResponder,
  LayoutChangeEvent,
} from 'react-native';
import { Check, Camera, Plus, FileText, Trash2 } from 'lucide-react-native';

import { Field, FieldValue, RepeaterSub } from '@/lib/intake/schema';
import { useIntake } from '@/lib/intake/store';
import { FieldLabel } from './FieldLabel';

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
      </View>
    );
  }

  return (
    <View className="mb-5">
      <FieldLabel n={n} label={field.label} hint={field.hint} required={field.required} />
      {renderInput(field, value, set)}
    </View>
  );
}

function renderInput(field: Field, value: FieldValue, set: (v: FieldValue) => void) {
  switch (field.type) {
    case 'text':
      return <TextInputField value={value as string | undefined} onChange={set} unit={field.unit} />;
    case 'textarea':
      return <TextAreaField value={value as string | undefined} onChange={set} />;
    case 'number':
      return (
        <TextInputField
          value={value != null ? String(value) : undefined}
          onChange={(v) => set(v === '' ? undefined : Number(v))}
          unit={field.unit}
          keyboard="decimal-pad"
        />
      );
    case 'date':
      return (
        <TextInputField
          value={value as string | undefined}
          onChange={set}
          placeholder="dd-mm-jjjj"
        />
      );
    case 'radio':
      return (
        <RadioField
          options={field.options ?? []}
          value={value as string | undefined}
          onChange={set}
        />
      );
    case 'multi':
      return (
        <MultiField
          options={field.options ?? []}
          value={(value as string[] | undefined) ?? []}
          onChange={set}
        />
      );
    case 'slider':
      return (
        <SliderField
          labels={field.labels ?? ['min', 'max']}
          value={typeof value === 'number' ? value : 3}
          onChange={(v) => set(v)}
        />
      );
    case 'photo':
      return (
        <PhotoField
          value={(value as string[] | undefined) ?? []}
          onChange={set}
        />
      );
    case 'file':
      return (
        <FileField
          value={(value as string[] | undefined) ?? []}
          onChange={set}
        />
      );
    case 'repeater':
      return (
        <RepeaterField
          sub={field.sub ?? []}
          value={(value as Record<string, string>[] | undefined) ?? []}
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

function TextAreaField({
  value,
  onChange,
}: {
  value?: string;
  onChange: (v: string) => void;
}) {
  return (
    <TextInput
      multiline
      numberOfLines={4}
      value={value ?? ''}
      onChangeText={onChange}
      placeholderTextColor="rgba(27,42,42,0.4)"
      className="rounded-xl border border-ink-8 bg-white px-4 py-3.5 font-sans text-[15px] text-ink"
      style={{ minHeight: 96, textAlignVertical: 'top' }}
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
    // Mutually exclusive "geen" — picking it clears everything else; picking
    // any other option clears a previously selected "geen". Mirrors how the
    // therapist reads the answers.
    if (o === 'geen') {
      onChange(value.includes('geen') ? [] : ['geen']);
      return;
    }
    const filtered = value.filter((v) => v !== 'geen');
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

/* ---------------------------------------------------------------- SLIDER */

function SliderField({
  labels,
  value,
  onChange,
}: {
  labels: [string, string];
  value: number; // 1..5
  onChange: (v: number) => void;
}) {
  const [width, setWidth] = useState(0);
  const onLayout = (e: LayoutChangeEvent) => setWidth(e.nativeEvent.layout.width);

  // 5-step scale; reuse the same PanResponder while the user drags so we
  // don't recreate it (and lose the active gesture) on every value change.
  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => true,
        onMoveShouldSetPanResponder: () => true,
        onPanResponderGrant: (_e, g) => updateFromX(g.x0),
        onPanResponderMove: (_e, g) => updateFromX(g.moveX),
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [width, onChange],
  );

  const updateFromX = (clientX: number) => {
    if (!width) return;
    // Approximation: treat clientX as offset since onPanResponderGrant uses
    // page-x. Good enough for this single-row component.
    const pct = Math.min(1, Math.max(0, clientX / width));
    const step = Math.round(pct * 4) + 1;
    if (step !== value) onChange(step);
  };

  const pct = ((value - 1) / 4) * 100;
  return (
    <View
      className="flex-row items-center gap-3 rounded-xl border border-ink-8 bg-white px-4 py-4"
      onLayout={onLayout}
    >
      <Text className="text-[12px] text-ink-50">{labels[0]}</Text>
      <View
        className="relative flex-1"
        style={{ height: 24, justifyContent: 'center' }}
        {...responder.panHandlers}
      >
        <View className="h-1 rounded-pill bg-ink-8" />
        <View
          className="absolute left-0 h-1 rounded-pill bg-mint-500"
          style={{ width: `${pct}%`, top: 11.5 }}
        />
        <View
          className="absolute h-5 w-5 rounded-full border-2 border-mint-500 bg-white"
          style={{ left: `${pct}%`, marginLeft: -10, top: 2 }}
        />
      </View>
      <Text className="font-semi text-[12px] text-ink">{labels[1]}</Text>
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
                <TextInput
                  value={row[s.id] ?? ''}
                  onChangeText={(v) => update(i, s.id, v)}
                  multiline={s.type === 'textarea'}
                  keyboardType={s.type === 'number' ? 'decimal-pad' : 'default'}
                  placeholderTextColor="rgba(27,42,42,0.4)"
                  className="rounded-lg border border-ink-8 bg-canvas px-3 py-2 font-sans text-[13.5px] text-ink"
                />
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

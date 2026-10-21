import { useRef } from 'react';
import { Pressable, Text, View } from 'react-native';
import { Check } from 'lucide-react-native';
import { IntakeTextInput as TextInput } from './IntakeScrollView';
import { MonthSelect } from './MonthSelect';
import { MONTHS, normalizeDate, formatDate, dateAnswered, type DateAnswer, type DatePoint, type DateConfig } from '@/lib/intake/dates';

export function MonthYearField({ value: raw, onChange, config = {}, label }: { value: unknown; onChange: (value: DateAnswer | undefined) => void; config?: DateConfig; label: string }) {
  const value = normalizeDate(raw, config);
  const previous = useRef<DateAnswer | undefined>(undefined);
  const unknown = value?.precision === 'unknown';
  const legacy = value?.precision === 'legacy' || (config.mode === 'range' && value && !['range', 'unknown'].includes(value.precision));
  const point = value?.precision !== 'range' ? value as DatePoint | undefined : undefined;
  const choices = [{ value: '', label: 'Kies een maand' }, ...MONTHS.map((month, i) => ({ value: String(i + 1), label: month })), ...(config.allow_year_only === false ? [] : [{ value: 'year', label: 'Maand onbekend' }])];
  const checkbox = (text: string, checked: boolean, toggle: () => void) => <Pressable accessibilityRole="checkbox" accessibilityLabel={text} accessibilityState={{ checked }} onPress={toggle} className="min-h-11 flex-row items-center gap-2 py-2">
    <View className={`h-5 w-5 items-center justify-center rounded border ${checked ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'}`}>{checked && <Check size={14} color="white" />}</View><Text className="text-[13px] text-ink">{text}</Text>
  </Pressable>;
  const renderPoint = (v: DatePoint | undefined, change: (point: DatePoint) => void, prefix = '', disabled = unknown) => <View className="flex-row gap-3">
    <View style={{ flex: 1.4, minWidth: 0 }}><Text className="mb-1.5 font-semi text-[13px] text-ink-70">{prefix}{config.month_label ?? 'Maand'}</Text>
      <MonthSelect label={`${label} — ${prefix}${config.month_label ?? 'Maand'}`} value={v?.precision === 'year' ? 'year' : String(v?.month ?? '')} options={choices} disabled={disabled} onChange={selected => change({ version: 1, precision: selected === 'year' ? 'year' : 'month', ...(v?.year !== undefined ? { year: v.year } : {}), ...(selected && selected !== 'year' ? { month: Number(selected) } : {}) })} />
    </View>
    <View style={{ flex: 1, minWidth: 0 }}><Text className="mb-1.5 font-semi text-[13px] text-ink-70">{prefix}{config.year_label ?? 'Jaar'}</Text>
      <TextInput accessibilityLabel={`${label} — ${prefix}${config.year_label ?? 'Jaar'}`} value={v?.year === undefined ? '' : String(v.year)} editable={!disabled} onChangeText={text => change({ version: 1, precision: v?.precision === 'year' ? 'year' : 'month', ...(v?.month !== undefined ? { month: v.month } : {}), ...(text.replace(/\D/g, '') ? { year: Number(text.replace(/\D/g, '').slice(0, 4)) } : {}) })} keyboardType="number-pad" maxLength={4} placeholder="Bijv. 2026" placeholderTextColor="rgba(27,42,42,0.4)" className="h-12 rounded-xl border border-ink-8 bg-white px-3 font-sans text-[15px] text-ink" style={{ opacity: disabled ? 0.45 : 1 }} />
    </View>
  </View>;
  if (legacy) return <View className="rounded-xl border border-ink-8 bg-white p-3"><Text className="text-[13px] text-ink-70">Eerder ingevuld: {formatDate(raw)}</Text><Pressable accessibilityRole="button" onPress={() => onChange(config.mode === 'range' ? { version: 1, precision: 'range' } : { version: 1, precision: 'month' })} className="py-2"><Text className="font-semi text-mint-700">Maand en jaar invullen</Text></Pressable></View>;
  return <View>
    {config.mode === 'range' ? <View className="gap-3">
      {renderPoint(value?.precision === 'range' ? value.start : undefined, start => onChange({ ...(value?.precision === 'range' ? value : { version: 1 as const, precision: 'range' as const }), start }), 'Van: ')}
      {renderPoint(value?.precision === 'range' ? value.end : undefined, end => onChange({ ...(value?.precision === 'range' ? value : { version: 1 as const, precision: 'range' as const }), end }), 'Tot: ', unknown || (value?.precision === 'range' && value.ongoing === true))}
      {!unknown && checkbox(config.ongoing_label ?? 'Nog steeds', value?.precision === 'range' && value.ongoing === true, () => onChange({ ...(value?.precision === 'range' ? value : { version: 1 as const, precision: 'range' as const }), ongoing: !(value?.precision === 'range' && value.ongoing) }))}
    </View> : renderPoint(unknown ? undefined : point, onChange)}
    {checkbox(config.unknown_label ?? 'Weet ik niet', unknown, () => { if (unknown) onChange(previous.current); else { previous.current = value; onChange({ version: 1, precision: 'unknown' }); } })}
    {value?.precision === 'day' && <Text className="text-[12px] text-ink-70">Eerder ingevulde exacte datum: {formatDate(value)}</Text>}
    {value && !unknown && !dateAnswered(value, config) && <Text accessibilityRole="alert" className="text-[12px] text-danger">Vul een geldige maand en een jaartal van vier cijfers in.{!config.allow_future ? ' Kies een moment in het verleden of deze maand.' : ''}{config.mode === 'range' ? ' De eindperiode mag niet vóór de beginperiode liggen.' : ''}</Text>}
  </View>;
}

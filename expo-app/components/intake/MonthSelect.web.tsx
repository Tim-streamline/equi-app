import { IntakeInputFrame } from './IntakeInputFrame';

export function MonthSelect({ value, options, onChange, disabled, label }: { value: string; options: { value: string; label: string }[]; onChange: (value: string) => void; disabled?: boolean; label: string }) {
  return <IntakeInputFrame className="h-12 rounded-xl border border-ink-8 bg-white" style={{ opacity: disabled ? 0.45 : 1 }}><select aria-label={label} value={value} disabled={disabled} onChange={e => onChange(e.target.value)} style={{ width: '100%', height: '100%', border: 0, borderRadius: 'inherit', background: 'transparent', color: '#1B2A2A', padding: '0 12px', fontFamily: 'inherit', fontSize: 15 }}>
    {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
  </select></IntakeInputFrame>;
}

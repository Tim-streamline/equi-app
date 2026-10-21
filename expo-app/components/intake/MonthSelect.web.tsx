export function MonthSelect({ value, options, onChange, disabled, label }: { value: string; options: { value: string; label: string }[]; onChange: (value: string) => void; disabled?: boolean; label: string }) {
  return <select aria-label={label} value={value} disabled={disabled} onChange={e => onChange(e.target.value)} style={{ width: '100%', height: 48, border: '1px solid rgba(27,42,42,0.08)', borderRadius: 12, background: 'white', color: '#1B2A2A', padding: '0 12px', fontFamily: 'inherit', fontSize: 15, opacity: disabled ? 0.45 : 1 }}>
    {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
  </select>;
}

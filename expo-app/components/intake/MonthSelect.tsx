import { useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import { ChevronDown } from 'lucide-react-native';

export function MonthSelect({ value, options, onChange, disabled, label }: { value: string; options: { value: string; label: string }[]; onChange: (value: string) => void; disabled?: boolean; label: string }) {
  const [open, setOpen] = useState(false);
  return <>
    <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ disabled, expanded: open }} disabled={disabled} onPress={() => setOpen(true)} className="h-12 flex-row items-center justify-between rounded-xl border border-ink-8 bg-white px-3" style={{ opacity: disabled ? 0.45 : 1 }}>
      <Text className="font-sans text-[15px] text-ink">{options.find(option => option.value === value)?.label ?? options[0]?.label}</Text><ChevronDown size={16} color="#1B2A2A" />
    </Pressable>
    <Modal transparent visible={open} animationType="fade" onRequestClose={() => setOpen(false)}>
      <View className="flex-1 justify-center bg-black/40 px-6 py-12"><View accessibilityViewIsModal className="max-h-full rounded-2xl bg-white p-4">
        <Text className="mb-2 font-bold text-[17px] text-ink">{label}</Text>
        <ScrollView>{options.map(option => <Pressable key={option.value} accessibilityRole="radio" accessibilityState={{ selected: value === option.value }} onPress={() => { onChange(option.value); setOpen(false); }} className="min-h-12 justify-center border-b border-ink-8 py-3"><Text className={value === option.value ? 'font-bold text-mint-700' : 'text-ink'}>{option.label}</Text></Pressable>)}</ScrollView>
        <Pressable accessibilityRole="button" onPress={() => setOpen(false)} className="items-center py-3"><Text className="font-bold text-mint-700">Sluiten</Text></Pressable>
      </View></View>
    </Modal>
  </>;
}

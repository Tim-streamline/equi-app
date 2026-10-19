import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { Camera, Check, ChevronDown, ChevronUp } from 'lucide-react-native';
import type { Field } from '@/lib/intake/schema';

/** Content and visibility come from the admin-managed photo configuration. */
export function PhotoInstructions({ field, design = false }: { field: Field; design?: boolean }) {
  const [expanded, setExpanded] = useState(true);
  if (!field.photoConfig?.instructions) return null;
  if (design) return <View className="my-4 rounded-[17px] bg-[#FAF8F5] p-4">
    <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={() => setExpanded(value => !value)} className="flex-row items-center gap-2">
      <Camera size={17} color="#128C85" />
      <Text className="flex-1 font-semi text-[13px] text-[#174B49]">{field.photoConfig.instruction_label || 'Zo maak je de foto'}</Text>
      {expanded ? <ChevronUp size={17} color="#128C85" /> : <ChevronDown size={17} color="#128C85" />}
    </Pressable>
    {expanded && <View className="mt-4 gap-3">
      {field.photoConfig.instructions.split(/\n\n+/).filter(Boolean).map((paragraph, index) => <View key={index} className="flex-row items-start gap-2">
        <Check size={17} color="#18B6AA" style={{ marginTop: 2 }} />
        <Text className="flex-1 text-[14px] leading-[22px] text-ink-70">{paragraph}</Text>
      </View>)}
    </View>}
  </View>;
  return <View className="mb-3 mt-4 gap-2">
    {!!field.photoConfig.instruction_label && <Text className="font-bold text-[16px] leading-[23px] text-ink">{field.photoConfig.instruction_label}</Text>}
    <Text className="text-[16px] leading-[23px] text-ink-70">{field.photoConfig.instructions}</Text>
  </View>;
}

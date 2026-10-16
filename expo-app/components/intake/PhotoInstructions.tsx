import { Text, View } from 'react-native';
import type { Field } from '@/lib/intake/schema';

/** Content and visibility come from the admin-managed photo configuration. */
export function PhotoInstructions({ field }: { field: Field }) {
  if (!field.photoConfig?.instructions) return null;
  return <View className="mb-3 mt-4 gap-2">
    {!!field.photoConfig.instruction_label && <Text className="font-bold text-[16px] leading-[23px] text-ink">{field.photoConfig.instruction_label}</Text>}
    <Text className="text-[16px] leading-[23px] text-ink-70">{field.photoConfig.instructions}</Text>
  </View>;
}

import { View, Text } from 'react-native';

type Props = {
  /** Two-digit field number shown as a small eyebrow ("01"). */
  n: number;
  label: string;
  hint?: string;
  required?: boolean;
};

export function FieldLabel({ n, label, hint, required }: Props) {
  return (
    <View className="mb-2">
      <View className="flex-row items-baseline gap-2">
        <Text className="font-bold text-[11px] tracking-display text-ink-50">
          {String(n).padStart(2, '0')}
        </Text>
        <Text className="flex-1 font-semi text-[14.5px] leading-[20px] text-ink">
          {label}
          {required && <Text className="text-danger"> *</Text>}
        </Text>
      </View>
      {hint && (
        <Text className="mt-0.5 pl-[22px] text-[12px] text-ink-50 leading-[16px]">
          {hint}
        </Text>
      )}
    </View>
  );
}

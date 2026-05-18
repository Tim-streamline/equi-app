import { Pressable, View, Text } from 'react-native';
import { ReactNode } from 'react';

type Props = {
  title?: string;
  description?: string;
  icon?: ReactNode;
  active?: boolean;
  dashed?: boolean;
  trailing?: ReactNode;
  onPress?: () => void;
  className?: string;
};

export function Bigchip({
  title,
  description,
  icon,
  active,
  dashed,
  trailing,
  onPress,
  className = '',
}: Props) {
  const base = 'w-full flex-row items-center gap-3.5 rounded-2xl border bg-white px-4 py-4';
  const state = active
    ? 'border-mint-500 bg-mint-50'
    : dashed
      ? 'border-dashed border-ink-15'
      : 'border-ink-8';
  return (
    <Pressable onPress={onPress} className={`${base} ${state} ${className}`}>
      {icon && (
        <View className={`h-10 w-10 items-center justify-center rounded-xl ${active ? 'bg-mint-500' : 'bg-mint-50'}`}>
          {icon}
        </View>
      )}
      <View className="flex-1">
        {title && <Text className="font-bold text-[15px] text-ink">{title}</Text>}
        {description && <Text className="mt-0.5 text-[12px] text-ink-50">{description}</Text>}
      </View>
      {trailing}
    </Pressable>
  );
}

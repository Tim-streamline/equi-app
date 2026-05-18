import { Pressable, View, Text } from 'react-native';
import { ReactNode } from 'react';

type Props = {
  label: string;
  sub?: string;
  icon: ReactNode;
  onPress?: () => void;
  className?: string;
};

export function Tile({ label, sub, icon, onPress, className = '' }: Props) {
  return (
    <Pressable
      onPress={onPress}
      className={`flex-1 gap-2 rounded-card border border-ink-8 bg-white p-4 ${className}`}
    >
      <View className="h-7 w-7 items-center justify-center">{icon}</View>
      <Text className="font-bold text-base text-ink">{label}</Text>
      {sub && <Text className="text-[12px] text-ink-50">{sub}</Text>}
    </Pressable>
  );
}

import { View, Text, Pressable } from 'react-native';
import { ReactNode } from 'react';

type Props = { children: ReactNode; action?: string; onAction?: () => void };

export function SectionTitle({ children, action, onAction }: Props) {
  return (
    <View className="flex-row items-baseline justify-between px-5 pb-2.5 pt-1">
      <Text
        className="font-bold text-[13px] uppercase text-ink-70"
        style={{ letterSpacing: 1.8 }}
      >
        {children}
      </Text>
      {action && (
        <Pressable onPress={onAction}>
          <Text className="font-semi text-[13px] text-mint-700">{action}</Text>
        </Pressable>
      )}
    </View>
  );
}

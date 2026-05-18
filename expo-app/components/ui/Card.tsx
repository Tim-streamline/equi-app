import { View, Pressable, PressableProps, ViewProps } from 'react-native';
import { ReactNode } from 'react';

type Props = {
  className?: string;
  flat?: boolean;
  onPress?: PressableProps['onPress'];
  children: ReactNode;
};

export function Card({ className = '', flat, onPress, children }: Props) {
  const base = `bg-white border border-ink-8 rounded-card p-[18px] gap-[10px] ${flat ? '' : 'shadow-sm'}`;
  if (onPress) {
    return (
      <Pressable onPress={onPress} className={`${base} ${className}`}>
        {children}
      </Pressable>
    );
  }
  return <View className={`${base} ${className}`}>{children}</View>;
}

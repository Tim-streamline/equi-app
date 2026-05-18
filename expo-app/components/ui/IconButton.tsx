import { Pressable, PressableProps } from 'react-native';
import { ReactNode } from 'react';

type Props = {
  children: ReactNode;
  onPress?: PressableProps['onPress'];
  tone?: 'light' | 'deep';
  size?: number;
  className?: string;
};

export function IconButton({ children, onPress, tone = 'light', size = 36, className = '' }: Props) {
  return (
    <Pressable
      onPress={onPress}
      className={`items-center justify-center rounded-full ${tone === 'deep' ? 'active:bg-white/10' : 'active:bg-ink-8'} ${className}`}
      style={{ width: size, height: size }}
      hitSlop={8}
    >
      {children}
    </Pressable>
  );
}

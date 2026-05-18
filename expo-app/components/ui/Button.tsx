import { Pressable, Text, View, PressableProps } from 'react-native';
import { cssInterop } from 'nativewind';
import { ReactNode } from 'react';

type Variant = 'primary' | 'deep' | 'ghost' | 'text';

type Props = {
  title?: string;
  variant?: Variant;
  onPress?: PressableProps['onPress'];
  disabled?: boolean;
  className?: string;
  textClassName?: string;
  leading?: ReactNode;
  trailing?: ReactNode;
  children?: ReactNode;
};

export function Button({
  title,
  variant = 'primary',
  onPress,
  disabled,
  className = '',
  textClassName = '',
  leading,
  trailing,
  children,
}: Props) {
  const base = 'w-full flex-row items-center justify-center gap-2 rounded-2xl px-6 py-4';
  const variants: Record<Variant, string> = {
    primary: 'bg-mint-500 active:bg-mint-700 shadow-sm',
    deep: 'bg-teal-700 active:bg-teal-800',
    ghost: 'bg-transparent border border-ink-8 active:bg-ink-8',
    text: 'bg-transparent py-2',
  };
  const textBase = 'font-semi text-base';
  const textVariants: Record<Variant, string> = {
    primary: 'text-white',
    deep: 'text-canvas',
    ghost: 'text-ink',
    text: 'text-mint-700',
  };
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      className={`${base} ${variants[variant]} ${disabled ? 'opacity-40' : ''} ${className}`}
    >
      {leading}
      {children
        ? children
        : title && <Text className={`${textBase} ${textVariants[variant]} ${textClassName}`}>{title}</Text>}
      {trailing}
    </Pressable>
  );
}

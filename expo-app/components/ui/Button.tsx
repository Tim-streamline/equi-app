import { Pressable, Text, View, PressableProps } from 'react-native';
import { cssInterop } from 'nativewind';
import { ReactNode } from 'react';

/** Icon/spinner colour that matches the primary button label (teal-900). */
export const PRIMARY_INK = '#083635';

type Variant = 'primary' | 'deep' | 'light' | 'ghost' | 'text';

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
  const base = 'w-full flex-row items-center justify-center gap-2 rounded-pill px-6';
  const variants: Record<Variant, string> = {
    primary: 'min-h-[52px] py-3.5 bg-mint-500 active:bg-mint-400 shadow-sm',
    deep: 'min-h-[52px] py-3.5 bg-teal-700 active:bg-teal-800',
    light: 'min-h-[52px] py-3.5 bg-white active:bg-mint-100',
    ghost: 'min-h-[52px] py-3.5 bg-transparent border border-ink-15 active:bg-ink-8',
    text: 'min-h-11 py-2 bg-transparent',
  };
  const textBase = 'font-semi text-base';
  const textVariants: Record<Variant, string> = {
    // Dark label on mint: white on mint-500 is only 2.4:1.
    primary: 'text-teal-900',
    deep: 'text-canvas',
    light: 'text-teal-700',
    ghost: 'text-ink',
    text: 'text-teal-600',
  };
  return (
    <Pressable
      accessibilityRole="button"
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

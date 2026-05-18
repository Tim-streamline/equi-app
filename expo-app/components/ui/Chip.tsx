import { View, Text, ViewProps } from 'react-native';
import { ReactNode } from 'react';

type ChipVariant = 'default' | 'outline' | 'warn' | 'danger' | 'success' | 'tag' | 'deep';

type Props = {
  label?: string;
  variant?: ChipVariant;
  className?: string;
  textClassName?: string;
  children?: ReactNode;
};

const styles: Record<ChipVariant, { box: string; text: string }> = {
  default: { box: 'bg-mint-50',           text: 'text-mint-700' },
  outline: { box: 'bg-transparent border border-ink-8', text: 'text-ink-70' },
  warn:    { box: 'bg-[#FBE9C6]',         text: 'text-[#8C6420]' },
  danger:  { box: 'bg-[#F4D6CF]',         text: 'text-[#8C3625]' },
  success: { box: 'bg-[#D2EEDF]',         text: 'text-[#1A6B49]' },
  tag:     { box: 'bg-white/90',          text: 'text-teal-700' },
  deep:    { box: 'bg-teal-700',          text: 'text-white' },
};

export function Chip({ label, variant = 'default', className = '', textClassName = '', children }: Props) {
  const s = styles[variant];
  return (
    <View className={`flex-row items-center self-start rounded-pill px-3 py-1.5 ${s.box} ${className}`}>
      {children
        ? children
        : label && (
            <Text className={`font-semi text-[12px] tracking-[0.04em] ${s.text} ${textClassName}`}>{label}</Text>
          )}
    </View>
  );
}

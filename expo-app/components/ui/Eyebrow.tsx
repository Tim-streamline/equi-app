import { Text } from 'react-native';

export function Eyebrow({ children, className = '' }: { children: string; className?: string }) {
  return (
    <Text
      className={`font-semi text-[11px] uppercase text-ink-70 ${className}`}
      style={{ letterSpacing: 1.4 }}
    >
      {children}
    </Text>
  );
}

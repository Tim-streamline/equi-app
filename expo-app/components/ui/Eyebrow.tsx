import { Text } from 'react-native';
import { EYEBROW } from './styles';

export function Eyebrow({ children, className = '' }: { children: string; className?: string }) {
  return <Text className={`${EYEBROW} text-ink-70 ${className}`}>{children}</Text>;
}

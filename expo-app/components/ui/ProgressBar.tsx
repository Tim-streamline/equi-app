import { View } from 'react-native';

export function ProgressBar({ value }: { value: number }) {
  return (
    <View className="h-1 overflow-hidden rounded-pill bg-ink-8">
      <View className="h-full rounded-pill bg-mint-500" style={{ width: `${Math.max(0, Math.min(100, value))}%` }} />
    </View>
  );
}

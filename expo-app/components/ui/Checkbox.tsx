import { Pressable, View } from 'react-native';
import { Check } from 'lucide-react-native';

type Props = { checked: boolean; onPress?: () => void; size?: number };

export function Checkbox({ checked, onPress, size = 22 }: Props) {
  return (
    <Pressable
      onPress={onPress}
      hitSlop={8}
      className={`items-center justify-center rounded-full border-2 ${checked ? 'border-mint-500 bg-mint-500' : 'border-ink-15 bg-white'}`}
      style={{ width: size, height: size }}
    >
      {checked && <Check size={size * 0.6} color="#fff" strokeWidth={3} />}
    </Pressable>
  );
}

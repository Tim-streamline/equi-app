import { View } from 'react-native';
import { Check } from 'lucide-react-native';

/** The round tick shared by the daily doses, the day sheet and the order list. */
export function DoneCircle({ done }: { done: boolean }) {
  return <View className={`h-7 w-7 items-center justify-center rounded-full border-2 ${done ? 'border-mint-500 bg-mint-500' : 'border-ink-30'}`}>
    {done && <Check size={17} color="white" />}
  </View>;
}

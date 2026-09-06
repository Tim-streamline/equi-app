import { Pressable, Text, View } from 'react-native';
import { X } from 'lucide-react-native';
import type { HorseDashboard } from '@/lib/horse-dashboard';

export function SeasonalTipCard({ tip, onOpen, onDismiss, dismissing }: {
  tip: NonNullable<HorseDashboard['seasonalTip']>;
  onOpen: () => void;
  onDismiss: () => void;
  dismissing: boolean;
}) {
  return (
    <View className="mb-4 rounded-[22px] bg-[#127A79] p-5">
      <View className="mb-2 min-h-[24px] flex-row items-center pr-8">
        <Text className="text-[10px] uppercase tracking-[1.5px] text-white/75">
          Seizoenstip · {tip.month}
        </Text>
      </View>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Deze seizoenstip verbergen"
        accessibilityHint="Verbergt alleen deze tip. Nieuwe seizoenstips blijven zichtbaar."
        disabled={dismissing}
        onPress={onDismiss}
        className="absolute right-2 top-2 h-11 w-11 items-center justify-center"
      >
        <X size={17} color="rgba(255,255,255,0.7)" />
      </Pressable>
      {!!tip.title && (
        <Text className="mb-2 font-semi text-[19px] leading-[25px] text-white">{tip.title}</Text>
      )}
      <Text numberOfLines={4} className="text-[14px] leading-[21px] text-white/90">
        {tip.intro ?? tip.body}
      </Text>
      <Pressable accessibilityRole="link" onPress={onOpen} className="mt-2 min-h-[44px] justify-center">
        <Text className="font-semi text-[14px] text-white">Lees Shelley&apos;s tip →</Text>
      </Pressable>
    </View>
  );
}

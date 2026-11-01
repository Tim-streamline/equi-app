import { Pressable, Text, View } from 'react-native';
import { useIntake } from '@/lib/intake/store';

/** Stays visible while a local write keeps failing, so later answers are never silently unsaved. */
export function UnsavedNotice({ className = '' }: { className?: string }) {
  const { unsaved, retrySave } = useIntake();
  if (!unsaved) return null;
  return <View accessibilityRole="alert" className={`flex-row items-center gap-3 rounded-2xl border border-danger/30 bg-white px-4 py-3 ${className}`}>
    <Text className="flex-1 text-[14px] leading-[20px] text-ink">
      Je laatste antwoorden zijn nog niet opgeslagen.
    </Text>
    <Pressable accessibilityRole="button" onPress={retrySave} hitSlop={8} className="active:opacity-60">
      <Text className="font-bold text-[14px] text-danger">Opnieuw proberen</Text>
    </Pressable>
  </View>;
}

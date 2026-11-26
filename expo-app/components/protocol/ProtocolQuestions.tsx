import { Pressable, Text, View } from 'react-native';
import { PROTOCOL_SCALE as S } from '@/components/protocol/styles';

/** The small line under every Protocol tab that tells owners where to go with questions. */
export function ProtocolQuestions({ onFaq, onShelby }: { onFaq: () => void; onShelby: () => void }) {
  const link = `${S.secondary} text-teal-600 underline`;
  return <View className="mt-8 items-center gap-1 px-2">
    <Text className={`${S.label} text-center text-ink`}>Vragen over je EquiBoost traject?</Text>
    <View className="flex-row flex-wrap items-center justify-center">
      <Pressable accessibilityRole="link" hitSlop={8} onPress={onFaq}><Text className={link}>Bekijk veelgestelde vragen</Text></Pressable>
      <Text className={`${S.secondary} text-ink-70`}> of </Text>
      <Pressable accessibilityRole="link" hitSlop={8} onPress={onShelby}><Text className={link}>vraag het Shelby</Text></Pressable>
    </View>
  </View>;
}

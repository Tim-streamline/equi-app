import { Text, View } from 'react-native';
import { useIntake } from '@/lib/intake/store';

export function SubmissionBlockNotice() {
  const { submissionBlocks } = useIntake();
  if (!submissionBlocks.length) return null;
  return <View accessibilityRole="alert" className="mb-3 rounded-2xl border border-danger/30 bg-white p-4">
    <Text className="font-bold text-[16px] text-danger">Je kunt de intake nog niet versturen</Text>
    {submissionBlocks.map((block, index) => <View key={`${block.section}:${block.field}:${index}`} className="mt-3">
      <Text className="font-bold text-ink">{block.label}</Text>
      <Text className="mt-1 text-sm text-ink-70">{block.answer}</Text>
      <Text className="mt-2 text-[14px] leading-[21px] text-ink">{block.message}</Text>
    </View>)}
  </View>;
}

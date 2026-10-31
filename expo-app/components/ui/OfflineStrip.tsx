import { Pressable, Text } from 'react-native';

type Props = {
  message: string;
  /** The page still shows data from an earlier load. */
  stale?: boolean;
  onRetry: () => void;
};

/**
 * Full-width notice directly under the header when a page could not load. Every
 * main tab uses this one style, so connection problems always look the same.
 */
export function OfflineStrip({ message, stale = false, onRetry }: Props) {
  // Messages often end with "Probeer opnieuw."; the strip already has that action.
  const reason = message.replace(/\s*Probeer (het )?opnieuw\.?\s*$/i, '').trim() || message;
  const text = stale ? `Laatst opgehaalde gegevens. ${reason}` : reason;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${text} Opnieuw proberen`}
      onPress={onRetry}
      className="min-h-11 flex-row items-center gap-3 bg-[#FFF3DF] px-5 py-2"
    >
      <Text className="flex-1 text-[14px] leading-[19px] text-[#7A5A16]">{text}</Text>
      <Text className="font-semi text-[14px] text-[#7A5A16] underline">Opnieuw</Text>
    </Pressable>
  );
}

import { View, Text, Linking } from 'react-native';

type Props = {
  /** Two-digit field number shown as a small eyebrow ("01"). */
  n: number;
  label: string;
  hint?: string;
  required?: boolean;
  /** Optional inline tappable hyperlink rendered within the label text. */
  link?: { text: string; url: string };
};

export function FieldLabel({ n, label, hint, required, link }: Props) {
  // When a link is present, split the label around its text so the matched
  // phrase renders as a tappable hyperlink (e.g. "privacyverklaring").
  const parts = link && label.includes(link.text) ? label.split(link.text) : null;
  return (
    <View className="mb-2">
      <View className="flex-row items-baseline gap-2">
        <Text className="font-bold text-[11px] tracking-display text-ink-50">
          {String(n).padStart(2, '0')}
        </Text>
        <Text className="flex-1 font-semi text-[14.5px] leading-[20px] text-ink">
          {parts ? (
            <>
              {parts[0]}
              <Text
                className="text-mint-700 underline"
                onPress={() => Linking.openURL(link!.url)}
              >
                {link!.text}
              </Text>
              {parts[1]}
            </>
          ) : (
            label
          )}
          {required && <Text className="text-danger"> *</Text>}
        </Text>
      </View>
      {hint && (
        <Text className="mt-0.5 pl-[22px] text-[12px] text-ink-50 leading-[16px]">
          {hint}
        </Text>
      )}
    </View>
  );
}

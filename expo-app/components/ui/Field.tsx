import { View, Text, TextInput, TextInputProps } from 'react-native';

type Props = TextInputProps & { label?: string; rows?: number };

export function Field({ label, rows, className, style, multiline, ...rest }: Props & { className?: string }) {
  return (
    <View className="mb-3">
      {label && (
        <Text className="mb-1.5 font-semi text-[12px] tracking-[0.04em] text-ink-70">{label}</Text>
      )}
      <TextInput
        multiline={multiline ?? !!rows}
        numberOfLines={rows}
        placeholderTextColor="rgba(27, 42, 42, 0.4)"
        className={`rounded-xl border border-ink-8 bg-white px-4 py-3.5 font-sans text-[15px] text-ink ${className || ''}`}
        style={[rows ? { minHeight: 24 * rows } : null, style as any]}
        {...rest}
      />
    </View>
  );
}

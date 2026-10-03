import { KeyboardTextInput as TextInput } from '@/components/ui/KeyboardForm';
import { useLayoutEffect, useRef, useState } from 'react';
import { Platform, Pressable, View, Text, TextInputProps } from 'react-native';
import { Eye, EyeOff } from 'lucide-react-native';

type Props = TextInputProps & { label?: string; rows?: number; passwordToggle?: boolean };

export function Field({ label, rows, className, style, multiline, passwordToggle = false, ...rest }: Props & { className?: string }) {
  const input = useRef<TextInput | null>(null);
  const cursor = useRef({ start: 0, end: 0 });
  const [visible, setVisible] = useState(false);
  const [restoreSelection, setRestoreSelection] = useState<{ start: number; end: number }>();
  useLayoutEffect(() => {
    if (!restoreSelection) return;
    input.current?.focus();
    const frame = requestAnimationFrame(() => {
      // RN Web also restores selection when the input type changes. Apply our
      // captured range after that effect, including mouse and keyboard toggles.
      if (Platform.OS === 'web') {
        const element = input.current as unknown as HTMLInputElement | null;
        element?.setSelectionRange(restoreSelection.start, restoreSelection.end);
      }
      setRestoreSelection(undefined);
    });
    return () => cancelAnimationFrame(frame);
  }, [restoreSelection]);
  return (
    <View className="mb-3">
      {label && (
        <Text className="mb-1.5 font-semi text-[12px] tracking-[0.04em] text-ink-70">{label}</Text>
      )}
      <View>
        <TextInput
          ref={input}
          multiline={multiline ?? !!rows}
          numberOfLines={rows}
          placeholderTextColor="rgba(27, 42, 42, 0.4)"
          className={`rounded-xl border border-ink-8 bg-white px-4 py-3.5 font-sans text-[15px] text-ink ${passwordToggle ? 'pr-14' : ''} ${className || ''}`}
          style={[rows ? { minHeight: 24 * rows } : null, style as any]}
          {...rest}
          secureTextEntry={passwordToggle ? !visible : rest.secureTextEntry}
          selection={restoreSelection ?? rest.selection}
          onSelectionChange={event => {
            if (!restoreSelection) cursor.current = { ...event.nativeEvent.selection };
            rest.onSelectionChange?.(event);
          }}
        />
        {passwordToggle && <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${label ?? 'Wachtwoord'} ${visible ? 'verbergen' : 'tonen'}`}
          accessibilityState={{ checked: visible, disabled: rest.editable === false }}
          disabled={rest.editable === false}
          onPointerDown={event => event.preventDefault()}
          onPress={() => {
            const element = Platform.OS === 'web' ? input.current as unknown as HTMLInputElement | null : null;
            setRestoreSelection(element && element.selectionStart !== null
              ? { start: element.selectionStart, end: element.selectionEnd ?? element.selectionStart }
              : { ...cursor.current });
            setVisible(value => !value);
          }}
          style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: 48, alignItems: 'center', justifyContent: 'center' }}>
          {visible ? <EyeOff size={20} color="#127A79" /> : <Eye size={20} color="#127A79" />}
        </Pressable>}
      </View>
    </View>
  );
}

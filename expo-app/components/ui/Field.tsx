import { KeyboardTextInput as TextInput } from '@/components/ui/KeyboardForm';
import { useLayoutEffect, useRef, useState } from 'react';
import { Platform, Pressable, View, Text, TextInputProps } from 'react-native';
import { Eye, EyeOff } from 'lucide-react-native';

type Props = TextInputProps & { label?: string; rows?: number; passwordToggle?: boolean; tone?: 'light' | 'deep' };

export function Field({ label, rows, className, style, multiline, passwordToggle = false, tone = 'light', ...rest }: Props & { className?: string }) {
  const deep = tone === 'deep';
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
        <Text className={`mb-1.5 font-semi text-[13px] tracking-[0.04em] ${deep ? 'text-mint-100' : 'text-ink-70'}`}>{label}</Text>
      )}
      <View>
        <TextInput
          ref={input}
          multiline={multiline ?? !!rows}
          numberOfLines={rows}
          placeholderTextColor={deep ? 'rgba(255, 255, 255, 0.6)' : 'rgba(27, 42, 42, 0.55)'}
          // 16px keeps iOS Safari from zooming the page when the field is focused.
          className={`rounded-xl border px-4 py-3.5 font-sans text-[16px] ${deep ? 'border-white/20 bg-white/10 text-white' : 'border-ink-15 bg-white text-ink'} ${passwordToggle ? 'pr-14' : ''} ${className || ''}`}
          style={[rows ? { minHeight: 24 * rows } : null, style as any]}
          accessibilityLabel={label}
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
          {visible ? <EyeOff size={20} color={deep ? '#fff' : '#127A79'} /> : <Eye size={20} color={deep ? '#fff' : '#127A79'} />}
        </Pressable>}
      </View>
    </View>
  );
}

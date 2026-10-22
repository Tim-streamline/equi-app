import { createContext, useContext, useState, type ReactNode, type Ref } from 'react';
import { Platform, View, type TextInput, type TextInputProps, type ViewProps } from 'react-native';
import { KeyboardTextInput } from '../ui/KeyboardForm';

const FrameFocus = createContext<((focused: boolean) => void) | null>(null);

/** The complete field owns focus, including suffixes and any embedded controls. */
export function IntakeInputFrame({ children, radius = 12, style, dataSet, ...props }: ViewProps & { children: ReactNode; radius?: number; dataSet?: Record<string, string> }) {
  const [focused, setFocused] = useState(false);
  return <FrameFocus.Provider value={setFocused}>
    <View {...props} {...{ dataSet: { ...dataSet, intakeInputFrame: 'true' } }} style={[{ borderRadius: radius }, style]}>
      {children}
      {Platform.OS !== 'web' && focused && <View pointerEvents="none" accessible={false} style={{ position: 'absolute', top: -5, bottom: -5, left: -5, right: -5, borderWidth: 2, borderColor: '#18BAB0', borderRadius: radius + 4 }} />}
    </View>
  </FrameFocus.Provider>;
}

export function IntakeTextInput({ onFocus, onBlur, ...props }: TextInputProps & { ref?: Ref<TextInput> }) {
  const focus = useContext(FrameFocus);
  return <KeyboardTextInput {...props} underlineColorAndroid={focus ? 'transparent' : props.underlineColorAndroid}
    onFocus={event => { focus?.(true); onFocus?.(event); }}
    onBlur={event => { focus?.(false); onBlur?.(event); }} />;
}

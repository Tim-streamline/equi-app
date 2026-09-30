import { createContext, useCallback, useContext, useEffect, useRef, useState, type Ref } from 'react';
import { Keyboard, KeyboardAvoidingView, Platform, ScrollView, TextInput, View, type KeyboardAvoidingViewProps, type ScrollViewProps, type TextInputProps } from 'react-native';

const InputContext = createContext<{ reveal: (input?: TextInput | null) => void; maxInputHeight: number }>({ reveal: () => {}, maxInputHeight: Infinity });

/** One resize owner per screen/modal. Scroll views handle focus inside that viewport. */
export function KeyboardViewport(props: KeyboardAvoidingViewProps) {
  const root = useRef<View>(null);
  const [origin, setOrigin] = useState(0);
  return <View ref={root} style={{ flex: 1 }} onLayout={() => root.current?.measureInWindow((_x, y) => setOrigin(y))}>
    <KeyboardAvoidingView {...props} style={[{ flex: 1 }, props.style]} enabled={Platform.OS !== 'web'}
      keyboardVerticalOffset={props.keyboardVerticalOffset ?? origin} behavior={Platform.OS === 'ios' ? 'padding' : 'height'} />
  </View>;
}

export type KeyboardScrollView = ScrollView;
export function KeyboardScrollView({ ref, children, onScroll, onLayout, onContentSizeChange, style, ...props }: ScrollViewProps & { ref?: Ref<ScrollView> }) {
  const scroll = useRef<ScrollView | null>(null);
  const offset = useRef(0);
  const focused = useRef<TextInput | null>(null);
  const frame = useRef<number | null>(null);
  const keyboardTop = useRef<number | null>(null);
  const [height, setHeight] = useState(Infinity);
  const attach = useCallback((value: ScrollView | null) => {
    scroll.current = value;
    if (typeof ref === 'function') ref(value);
    else if (ref) ref.current = value;
  }, [ref]);
  const reveal = useCallback((nextInput?: TextInput | null) => {
    if (nextInput) focused.current = nextInput;
    if (frame.current !== null) cancelAnimationFrame(frame.current);
    frame.current = requestAnimationFrame(() => {
      frame.current = null;
      const input = TextInput.State.currentlyFocusedInput();
      const viewport = scroll.current;
      if (!input || !viewport || input !== focused.current) return;
      viewport.getNativeScrollRef()?.measureInWindow((_x, top, _width, viewportHeight) => {
        input.measureInWindow((_ix, inputTop, _iw, inputHeight) => {
          if (scroll.current !== viewport || TextInput.State.currentlyFocusedInput() !== input) return;
          const browserBottom = Platform.OS === 'web' && typeof window !== 'undefined' && window.visualViewport
            ? window.visualViewport.height + window.visualViewport.offsetTop : Infinity;
          const bottom = Math.min(top + viewportHeight, keyboardTop.current ?? Infinity, browserBottom) - 16;
          const delta = inputTop < top + 12 || inputHeight > bottom - top - 12
            ? inputTop - top - 12
            : Math.max(0, inputTop + inputHeight - bottom);
          if (Math.abs(delta) > 1) viewport.scrollTo({ y: Math.max(0, offset.current + delta), animated: true });
        });
      });
    });
  }, []);
  useEffect(() => {
    const update = (event: { endCoordinates: { screenY: number } }) => { keyboardTop.current = event.endCoordinates.screenY; reveal(); };
    const show = Keyboard.addListener('keyboardDidShow', update);
    const change = Keyboard.addListener('keyboardDidChangeFrame', update);
    const hide = Keyboard.addListener('keyboardDidHide', () => { keyboardTop.current = null; });
    const viewport = Platform.OS === 'web' && typeof window !== 'undefined' ? window.visualViewport : null;
    const resized = () => reveal();
    viewport?.addEventListener('resize', resized);
    return () => {
      show.remove(); change.remove(); hide.remove();
      viewport?.removeEventListener('resize', resized);
      if (frame.current !== null) cancelAnimationFrame(frame.current);
    };
  }, [reveal]);
  return <InputContext.Provider value={{ reveal, maxInputHeight: Math.max(44, height - 32) }}>
    <ScrollView {...props} ref={attach} style={[{ minHeight: 0 }, style]}
      keyboardShouldPersistTaps={props.keyboardShouldPersistTaps ?? 'handled'}
      keyboardDismissMode={props.keyboardDismissMode ?? 'none'}
      automaticallyAdjustKeyboardInsets={false}
      scrollEventThrottle={16}
      onScroll={event => { offset.current = event.nativeEvent.contentOffset.y; onScroll?.(event); }}
      onLayout={event => { setHeight(event.nativeEvent.layout.height); onLayout?.(event); if (keyboardTop.current !== null) reveal(); }}
      onContentSizeChange={(width, nextHeight) => { onContentSizeChange?.(width, nextHeight); if (keyboardTop.current !== null) reveal(); }}>
      {children}
    </ScrollView>
  </InputContext.Provider>;
}

export type KeyboardTextInput = TextInput;
export function KeyboardTextInput({ ref, style, onLayout, ...props }: TextInputProps & { ref?: Ref<TextInput> }) {
  const { reveal, maxInputHeight } = useContext(InputContext);
  const [naturalHeight, setNaturalHeight] = useState(0);
  const input = useRef<TextInput | null>(null);
  const attach = useCallback((value: TextInput | null) => {
    input.current = value;
    if (typeof ref === 'function') ref(value);
    else if (ref) ref.current = value;
  }, [ref]);
  return <TextInput {...props} ref={attach}
    style={[style, props.multiline && Number.isFinite(maxInputHeight) ? {
      maxHeight: maxInputHeight,
      ...(naturalHeight > maxInputHeight ? { minHeight: maxInputHeight } : {}),
    } : null]}
    onLayout={event => { setNaturalHeight(previous => Math.max(previous, event.nativeEvent.layout.height)); onLayout?.(event); }}
    onFocus={event => { props.onFocus?.(event); reveal(input.current); }}
    onSelectionChange={event => { props.onSelectionChange?.(event); reveal(input.current); }} />;
}

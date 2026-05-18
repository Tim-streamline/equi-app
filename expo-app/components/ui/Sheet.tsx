import { Modal, Pressable, View, KeyboardAvoidingView, Platform } from 'react-native';
import { ReactNode, useEffect, useRef } from 'react';
import { Animated, Easing } from 'react-native';

type Props = {
  open: boolean;
  onClose: () => void;
  children: ReactNode;
  heightFraction?: number; // 0..1 of screen height
};

export function Sheet({ open, onClose, children, heightFraction = 0.78 }: Props) {
  const slide = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    Animated.timing(slide, {
      toValue: open ? 1 : 0,
      duration: 280,
      easing: Easing.bezier(0.22, 0.61, 0.36, 1),
      useNativeDriver: true,
    }).start();
  }, [open, slide]);

  const translateY = slide.interpolate({ inputRange: [0, 1], outputRange: [40, 0] });
  const opacity = slide;

  return (
    <Modal visible={open} transparent animationType="fade" onRequestClose={onClose} statusBarTranslucent>
      <Animated.View
        style={{ flex: 1, backgroundColor: 'rgba(11, 42, 41, 0.45)', opacity, justifyContent: 'flex-end' }}
      >
        <Pressable style={{ flex: 1 }} onPress={onClose} />
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <Animated.View
            style={{ transform: [{ translateY }] }}
            className="rounded-t-3xl bg-white px-5 pb-8 pt-3"
          >
            <View className="mx-auto mb-4 h-1 w-9 rounded-pill bg-ink-15" />
            <View style={{ maxHeight: `${heightFraction * 100}%` as any }}>{children}</View>
          </Animated.View>
        </KeyboardAvoidingView>
      </Animated.View>
    </Modal>
  );
}

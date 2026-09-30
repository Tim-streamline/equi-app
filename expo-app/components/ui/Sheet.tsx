import { Modal, Pressable, View } from 'react-native';
import { ReactNode, useEffect, useRef } from 'react';
import { Animated, Easing } from 'react-native';
import { KeyboardViewport, KeyboardScrollView } from './KeyboardForm';

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
        <KeyboardViewport style={{ flex: 1, justifyContent: 'flex-end' }}>
        <Pressable style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 }} onPress={onClose} />
          <Animated.View
            style={{ transform: [{ translateY }], maxHeight: `${heightFraction * 100}%`, flexShrink: 1 }}
            className="rounded-t-3xl bg-white px-5 pb-8 pt-3"
          >
            <View className="mx-auto mb-4 h-1 w-9 rounded-pill bg-ink-15" />
            <KeyboardScrollView style={{ flexGrow: 0 }} contentContainerStyle={{ paddingBottom: 16 }}>{children}</KeyboardScrollView>
          </Animated.View>
        </KeyboardViewport>
      </Animated.View>
    </Modal>
  );
}

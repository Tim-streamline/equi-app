import { useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Easing, Pressable, Text, View } from 'react-native';
import { Check, Image as ImageIcon, X, Zap } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { C, RoundButton, s } from './ScannerUI';

export function FeedProcessingAnimation() {
  const motion = useRef(new Animated.Value(0)).current;
  const [reducedMotion, setReducedMotion] = useState(true);
  useEffect(() => {
    let mounted = true;
    AccessibilityInfo.isReduceMotionEnabled().then(value => { if (mounted) setReducedMotion(value); });
    const listener = AccessibilityInfo.addEventListener('reduceMotionChanged', setReducedMotion);
    return () => { mounted = false; listener.remove(); };
  }, []);
  useEffect(() => {
    if (reducedMotion) return;
    const animation = Animated.loop(Animated.timing(motion, { toValue: 1, duration: 2400, easing: Easing.linear, useNativeDriver: true }));
    animation.start();
    return () => { animation.stop(); motion.setValue(0); };
  }, [motion, reducedMotion]);
  return <View accessible={false} importantForAccessibility="no-hide-descendants" style={{ width: 140, height: 150 }}>
    <View style={{ position: 'absolute', width: 136, height: 136, top: 6, left: 2, borderRadius: 68, borderWidth: 5, borderColor: '#D0F3EE', borderTopColor: C.teal, opacity: 0.35 }} />
    <View style={{ position: 'absolute', top: 14, left: 65, width: 34, height: 18, borderRadius: 6, backgroundColor: C.teal, transform: [{ rotate: '-18deg' }] }} />
    <View style={{ position: 'absolute', top: 17, left: 93, width: 24, height: 6, borderRadius: 3, backgroundColor: C.deep, transform: [{ rotate: '-18deg' }] }} />
    {[0, 1, 2, 3, 4, 5].map(i => <Animated.View key={i} style={{ position: 'absolute', left: 45 + (i * 7) % 28, top: 32 + i * 4, width: 8, height: 11, borderRadius: 6, backgroundColor: i % 2 ? C.mint : '#82DDD3', opacity: reducedMotion ? 0.8 : motion.interpolate({ inputRange: [0, 0.15, 0.8, 1], outputRange: [0, 1, 1, 0] }), transform: [{ translateY: reducedMotion ? i * 3 : motion.interpolate({ inputRange: [0, 1], outputRange: [-10 - i * 2, 50 - i * 2] }) }] }} />)}
    <View style={{ position: 'absolute', top: 88, left: 34, width: 72, height: 24, borderTopLeftRadius: 36, borderTopRightRadius: 36, backgroundColor: '#82DDD3' }} />
    <View style={{ position: 'absolute', top: 104, left: 28, width: 84, height: 40, borderBottomLeftRadius: 14, borderBottomRightRadius: 14, backgroundColor: C.deep }} />
    <View style={{ position: 'absolute', top: 100, left: 22, width: 96, height: 10, borderRadius: 5, backgroundColor: '#084948' }} />
    <View style={{ position: 'absolute', top: 120, left: 56, width: 28, height: 4, borderRadius: 2, backgroundColor: C.teal }} />
  </View>;
}

// The caller supplies actual progress; this component never advances or creates results.
export function ScannerProcessingState({ title, steps = [], activeStep = 0 }: { title: string; steps?: string[]; activeStep?: number }) {
  return <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', padding: 32, gap: 28, backgroundColor: C.canvas }}>
    <FeedProcessingAnimation />
    <Text accessibilityLiveRegion="polite" style={[s.heading, { fontSize: 24, lineHeight: 30, textAlign: 'center' }]}>{title}</Text>
    {!!steps.length && <View style={{ gap: 18, alignSelf: 'stretch' }}>{steps.map((step, i) => <View key={step} style={{ flexDirection: 'row', gap: 12, alignItems: 'center', opacity: i > activeStep ? 0.45 : 1 }}>
      <View style={{ width: 24, height: 24, borderRadius: 12, backgroundColor: i < activeStep ? C.teal : i === activeStep ? C.pale : C.border, alignItems: 'center', justifyContent: 'center' }}>{i < activeStep ? <Check size={16} color="#fff" /> : <View style={{ width: 7, height: 7, borderRadius: 4, backgroundColor: i === activeStep ? C.teal : C.muted }} />}</View>
      <Text style={[s.body, { flex: 1 }]}>{step}</Text>
    </View>)}</View>}
  </View>;
}

// This is a camera overlay, not a camera implementation. A real camera stream
// must be supplied as children before enabling it in the scan flow.
export function ScannerCameraOverlay({ children, title, hint, onClose, onAlbum, onCapture, onFlash }: {
  children?: ReactNode; title: string; hint: string; onClose?: () => void; onAlbum?: () => void; onCapture?: () => void; onFlash?: () => void;
}) {
  return <View style={{ flex: 1, backgroundColor: '#0A1414' }}>
    {children && <View style={{ position: 'absolute', inset: 0 }}>{children}</View>}
    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, paddingTop: 4 }}>
      <RoundButton label="Sluiten" onPress={onClose ?? (() => {})}><X size={22} color="#fff" /></RoundButton><Text style={[s.body, { color: '#fff', fontSize: 16, fontFamily: 'SourceSans3_600SemiBold' }]}>{title}</Text><Pressable accessibilityRole="button" accessibilityLabel="Flitser" disabled={!onFlash} onPress={onFlash} style={s.round}><Zap size={20} color="#fff" /></Pressable>
    </View>
    <View style={{ flex: 1, paddingHorizontal: 24, justifyContent: 'center', alignItems: 'center', gap: 20 }}>
      <View style={{ width: '78%', aspectRatio: 3 / 4 }}>
        {(['tl', 'tr', 'bl', 'br'] as const).map(corner => <View key={corner} style={{ position: 'absolute', width: 30, height: 30, borderColor: C.mint,
          ...(corner[0] === 't' ? { top: 0, borderTopWidth: 3 } : { bottom: 0, borderBottomWidth: 3 }),
          ...(corner[1] === 'l' ? { left: 0, borderLeftWidth: 3 } : { right: 0, borderRightWidth: 3 }),
          borderTopLeftRadius: corner === 'tl' ? 12 : 0, borderTopRightRadius: corner === 'tr' ? 12 : 0, borderBottomLeftRadius: corner === 'bl' ? 12 : 0, borderBottomRightRadius: corner === 'br' ? 12 : 0 }} />)}
      </View>
      <Text style={[s.body, { color: 'rgba(255,255,255,0.88)', textAlign: 'center', maxWidth: 290 }]}>{hint}</Text>
    </View>
    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 24, paddingBottom: 34, gap: 12 }}>
      <Pressable accessibilityRole="button" accessibilityLabel="Album" disabled={!onAlbum} onPress={onAlbum} style={{ width: 56, height: 56, borderRadius: 14, borderWidth: 1, borderColor: 'rgba(255,255,255,0.3)', alignItems: 'center', justifyContent: 'center', gap: 2 }}><ImageIcon size={20} color="#fff" /><Text style={[s.small, { fontSize: 11, color: '#fff' }]}>Album</Text></Pressable>
      <Pressable accessibilityRole="button" accessibilityLabel="Foto maken" disabled={!onCapture} onPress={onCapture} style={{ width: 78, height: 78, borderRadius: 39, borderWidth: 5, borderColor: '#526060', backgroundColor: '#fff' }} />
      <View style={{ width: 56 }} />
    </View>
  </View>;
}

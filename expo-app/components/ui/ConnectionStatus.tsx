// Compact PowerSync connection indicator for the topbar: a colored dot + short
// Dutch label. The dot gently pulses while connecting or syncing so the header
// reads "live". Status comes from the DbProvider, which mirrors PowerSync's
// real statusChanged lifecycle.

import { useEffect } from 'react';
import { View, Text } from 'react-native';
import Animated, {
  cancelAnimation,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';
import { useDb } from '@/db/provider';

const CONFIG = {
  connected: { label: 'Online', dot: '#2EA875', pulse: false },
  syncing: { label: 'Synchroniseren', dot: '#18BAB0', pulse: true },
  connecting: { label: 'Verbinden', dot: '#D9A441', pulse: true },
  error: { label: 'Offline', dot: '#C2543E', pulse: false },
  idle: { label: 'Offline', dot: 'rgba(27,42,42,0.3)', pulse: false },
} as const;

function Dot({ color, pulse }: { color: string; pulse: boolean }) {
  const opacity = useSharedValue(1);

  useEffect(() => {
    if (pulse) {
      opacity.value = withRepeat(withTiming(0.25, { duration: 700 }), -1, true);
    } else {
      cancelAnimation(opacity);
      opacity.value = withTiming(1, { duration: 200 });
    }
  }, [pulse, opacity]);

  const style = useAnimatedStyle(() => ({ opacity: opacity.value }));

  return (
    <Animated.View
      style={[{ width: 7, height: 7, borderRadius: 4, backgroundColor: color }, style]}
    />
  );
}

export function ConnectionStatus() {
  const { syncStatus } = useDb();
  const cfg = CONFIG[syncStatus] ?? CONFIG.idle;

  return (
    <View className="flex-row items-center gap-1.5 rounded-pill bg-ink-8 px-2.5 py-1">
      <Dot color={cfg.dot} pulse={cfg.pulse} />
      <Text className="font-medium text-[11px] text-ink-50">{cfg.label}</Text>
    </View>
  );
}

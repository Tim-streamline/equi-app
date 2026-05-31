import { useEffect } from 'react';
import { View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import Animated, {
  useSharedValue,
  useAnimatedStyle,
  withRepeat,
  withTiming,
  Easing,
} from 'react-native-reanimated';
import { X, Zap, History, QrCode } from 'lucide-react-native';
import { useScanResults, useValue } from '@/db/hooks';

export default function ScannerCameraScreen() {
  const y = useSharedValue(0);
  const hint = useValue('scannerHintText') as string;
  const scans = useScanResults();
  const lastScan = scans.find((s: any) => s.score === 62) ?? scans[0];

  useEffect(() => {
    y.value = withRepeat(
      withTiming(1, { duration: 2200, easing: Easing.inOut(Easing.quad) }),
      -1,
      true,
    );
  }, [y]);

  const lineStyle = useAnimatedStyle(() => ({
    transform: [{ translateY: y.value * 220 - 110 }],
  }));

  return (
    <View className="flex-1" style={{ backgroundColor: '#0a1414' }}>
      <StatusBar style="light" />
      <SafeAreaView style={{ flex: 1 }} edges={['top']}>
        <View
          pointerEvents="none"
          style={{
            position: 'absolute', top: -120, left: -80,
            width: 320, height: 320, borderRadius: 160,
            backgroundColor: 'rgba(18,122,121,0.18)',
          }}
        />
        <View
          pointerEvents="none"
          style={{
            position: 'absolute', bottom: -100, right: -80,
            width: 280, height: 280, borderRadius: 140,
            backgroundColor: 'rgba(24,186,176,0.10)',
          }}
        />

        <View className="flex-row items-center justify-between px-5 py-2">
          <Pressable
            onPress={() => router.push('/(tabs)/(pager)/home')}
            className="h-9 w-9 items-center justify-center rounded-full bg-white/10"
          >
            <X size={20} color="#fff" />
          </Pressable>
          <Text className="font-semi text-white text-[16px]">Scanner</Text>
          <Pressable className="h-9 w-9 items-center justify-center rounded-full bg-white/10">
            <Zap size={18} color="#fff" />
          </Pressable>
        </View>

        <View className="flex-1 items-center justify-center">
          <View style={{ width: 240, height: 240, position: 'relative', borderRadius: 20, overflow: 'hidden' }}>
            {(['tl', 'tr', 'bl', 'br'] as const).map((c) => {
              const base = { position: 'absolute' as const, width: 28, height: 28, borderColor: '#18BAB0', borderRadius: 4 };
              const map: Record<typeof c, any> = {
                tl: { top: 0, left: 0, borderTopWidth: 3, borderLeftWidth: 3 },
                tr: { top: 0, right: 0, borderTopWidth: 3, borderRightWidth: 3 },
                bl: { bottom: 0, left: 0, borderBottomWidth: 3, borderLeftWidth: 3 },
                br: { bottom: 0, right: 0, borderBottomWidth: 3, borderRightWidth: 3 },
              };
              return <View key={c} style={[base, map[c]]} />;
            })}
            <Animated.View
              style={[
                {
                  position: 'absolute', left: 16, right: 16, top: '50%',
                  height: 2, backgroundColor: '#18BAB0',
                  shadowColor: '#18BAB0', shadowOpacity: 0.8, shadowRadius: 8,
                },
                lineStyle,
              ]}
            />
          </View>
          <Text className="mt-6 text-white/70 text-[13px] px-10 text-center leading-[20px]">
            {hint}
          </Text>
        </View>

        <View className="items-center pb-10">
          <View className="flex-row items-center gap-14">
            <Pressable
              onPress={() => router.push('/(tabs)/scanner/history')}
              className="h-11 w-11 items-center justify-center rounded-full bg-white/10"
            >
              <History size={20} color="#fff" />
            </Pressable>
            <Pressable
              onPress={() =>
                router.push({
                  pathname: '/(tabs)/scanner/result',
                  params: { id: lastScan?.id ?? '' },
                } as any)
              }
              className="h-20 w-20 items-center justify-center rounded-full bg-white"
            >
              <View className="h-16 w-16 rounded-full border-4 border-[#0a1414]" />
            </Pressable>
            <Pressable className="h-11 w-11 items-center justify-center rounded-full bg-white/10">
              <QrCode size={20} color="#fff" />
            </Pressable>
          </View>
          <Text
            className="mt-4 font-semi text-white/50"
            style={{ fontSize: 11, letterSpacing: 1.2 }}
          >
            TIK OM SCAN TE MAKEN
          </Text>
        </View>
      </SafeAreaView>
    </View>
  );
}

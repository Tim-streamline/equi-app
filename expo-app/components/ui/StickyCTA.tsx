import { View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ReactNode } from 'react';

export function StickyCTA({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0 }}>
      <LinearGradient
        colors={['rgba(251, 248, 243, 0)', '#FBF8F3']}
        locations={[0, 0.4]}
        style={{ paddingTop: 24, paddingHorizontal: 20, paddingBottom: 16 + insets.bottom, gap: 8 }}
      >
        {children}
      </LinearGradient>
    </View>
  );
}

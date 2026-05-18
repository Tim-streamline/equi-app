import { View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ReactNode } from 'react';
import { TAB_BAR_BASE_HEIGHT } from '@/hooks/useTabBarPadding';

type Props = { children: ReactNode; inTabs?: boolean };

export function StickyCTA({ children, inTabs }: Props) {
  const insets = useSafeAreaInsets();
  const bottomOffset = inTabs ? TAB_BAR_BASE_HEIGHT + insets.bottom : 0;
  const paddingBottom = inTabs ? 16 : 16 + insets.bottom;
  return (
    <View style={{ position: 'absolute', left: 0, right: 0, bottom: bottomOffset }}>
      <LinearGradient
        colors={['rgba(251, 248, 243, 0)', '#FBF8F3']}
        locations={[0, 0.4]}
        style={{ paddingTop: 24, paddingHorizontal: 20, paddingBottom, gap: 8 }}
      >
        {children}
      </LinearGradient>
    </View>
  );
}

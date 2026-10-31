import type { ReactNode } from 'react';
import { View, Pressable, Animated } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { UserRound } from 'lucide-react-native';
import { BRAND_NAME } from '@/constants/brand';
import { useHeaderActions } from '@/components/navigation/headerActions';

/** Icon colour for header buttons. */
export const HEADER_ICON = '#466362';

/** A 44pt round icon button in the header style (account, page actions). */
export function HeaderButton({ label, onPress, children }: { label: string; onPress: () => void; children: ReactNode }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={onPress}
      className="h-11 w-11 items-center justify-center rounded-full active:bg-ink-8"
    >
      {children}
    </Pressable>
  );
}

/** Page titles shown in the header, keyed by pager route name. */
const TITLES: Record<string, string> = {
  home: BRAND_NAME,
  protocol: 'Protocol',
  library: 'Bibliotheek',
  community: 'Community',
};

/** The pager's scroll position (fractional page index) plus the settled page. */
export type PagerProgress = {
  position: Animated.AnimatedInterpolation<number>;
  index: number;
  routes: string[];
};

// How far a title slides while it fades; it moves the same way as the page.
const SLIDE = 14;

/**
 * Visible only while page `index` is (nearly) in view: it fades and slides out
 * during the first half of a page transition and in during the second half.
 */
function pageFade(position: PagerProgress['position'], index: number) {
  return {
    opacity: position.interpolate({
      inputRange: [index - 1, index - 0.5, index, index + 0.5, index + 1],
      outputRange: [0, 0, 1, 0, 0],
      extrapolate: 'clamp',
    }),
    transform: [{
      translateX: position.interpolate({
        inputRange: [index - 1, index, index + 1],
        outputRange: [SLIDE, 0, -SLIDE],
        extrapolate: 'clamp',
      }),
    }],
  };
}

/**
 * Slim header shared by the main tabs. It sits outside the tab scenes, so it
 * stays put while pages scroll and does not jump when switching tabs. The
 * scenes below must not add the top safe-area inset again.
 *
 * The title follows the pager scroll: the old page name fades and slides out
 * during the first half of the transition, the new one comes in during the
 * second half, so the two never overlap. Page actions (see `useHeaderAction`)
 * do the same, so each page's action only shows on that page. They are laid
 * out against the account button, which never moves.
 */
export function AppHeader({ pager }: { pager: PagerProgress | null }) {
  const routes = pager?.routes ?? ['home'];
  const current = pager?.index ?? 0;
  const actions = useHeaderActions();
  return (
    <SafeAreaView edges={['top']}>
      {/* Hairline separates the bar from content scrolling underneath. */}
      <View className="h-[52px] flex-row items-center justify-between border-b pl-5 pr-2" style={{ borderBottomColor: 'rgba(27,42,42,0.1)' }}>
        <View accessibilityRole="header" className="h-7 flex-1 justify-center">
          {routes.map((route, index) => {
            const title = TITLES[route] ?? route;
            const hidden = index !== current;
            const style = pager ? pageFade(pager.position, index) : { opacity: hidden ? 0 : 1 };
            return (
              <Animated.Text
                key={route}
                numberOfLines={1}
                maxFontSizeMultiplier={1.3}
                accessibilityElementsHidden={hidden}
                importantForAccessibility={hidden ? 'no-hide-descendants' : 'auto'}
                style={[{ position: 'absolute', left: 0, right: 0, includeFontPadding: false, fontFamily: 'SourceSans3_700Bold', fontSize: 15, letterSpacing: 1, color: '#106E6D' }, style]}
              >
                {title}
              </Animated.Text>
            );
          })}
        </View>
        {/* Spans the bar up to the account button so actions stay inside their parent's
            bounds (Android only delivers touches there); taps pass through elsewhere. */}
        <View pointerEvents="box-none" style={{ position: 'absolute', left: 0, top: 0, bottom: 0, right: 56 }}>
          {routes.map((route, index) => {
            if (actions[route] == null) return null;
            const hidden = index !== current;
            return (
              <Animated.View
                key={route}
                pointerEvents={hidden ? 'none' : 'box-none'}
                accessibilityElementsHidden={hidden}
                importantForAccessibility={hidden ? 'no-hide-descendants' : 'auto'}
                style={[{ position: 'absolute', right: 0, top: 0, bottom: 0, justifyContent: 'center' }, pager ? pageFade(pager.position, index) : { opacity: hidden ? 0 : 1 }]}
              >
                {actions[route]}
              </Animated.View>
            );
          })}
        </View>
        <HeaderButton label="Account openen" onPress={() => router.push('/(tabs)/account')}>
          <UserRound size={22} color={HEADER_ICON} />
        </HeaderButton>
      </View>
    </SafeAreaView>
  );
}

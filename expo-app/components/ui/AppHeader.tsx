import { useMemo, useState, type ReactNode } from 'react';
import { View, Text, Animated, type StyleProp, type ViewStyle } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { UserRound } from 'lucide-react-native';
import type { MaterialTopTabBarProps } from '@react-navigation/material-top-tabs';
import type { PagerScreenOptions } from '@/components/navigation/headerActions';
import { IconButton } from './IconButton';

/** Icon colour for header buttons. */
export const HEADER_ICON = '#466362';

type Position = MaterialTopTabBarProps['position'];

// How far a title slides while it fades; it moves the same way as the page.
const SLIDE = 14;
const HIDDEN = { opacity: 0 };
const VISIBLE = { opacity: 1 };

/**
 * Style for page `index`'s slot while the pager moves from page `from` to `to`.
 * The old page fades and slides out over the first half of the move, the new
 * one in over the second half, so the two never overlap. Pages passed on the
 * way (jumping several tabs) stay hidden.
 */
function pageFade(position: Position, index: number, from: number, to: number) {
  if (index !== from && index !== to) return HIDDEN;
  if (from === to) return VISIBLE;
  const leaving = index === from;
  const direction = Math.sign(to - from);
  const middle = (from + to) / 2;
  const [start, end] = leaving ? [from, middle] : [middle, to];
  const opacity = leaving ? [1, 0] : [0, 1];
  const offset = leaving ? [0, -SLIDE * direction] : [SLIDE * direction, 0];
  // interpolate() needs an increasing input range.
  const ascending = start < end;
  const inputRange = ascending ? [start, end] : [end, start];
  const ordered = (values: number[]) => (ascending ? values : [...values].reverse());
  return {
    opacity: position.interpolate({ inputRange, outputRange: ordered(opacity), extrapolate: 'clamp' }),
    transform: [{ translateX: position.interpolate({ inputRange, outputRange: ordered(offset), extrapolate: 'clamp' }) }],
  };
}

/** One page's title or action, stacked with the other pages' and faded by `pageFade`. */
function PageSlot({ hidden, style, children }: { hidden: boolean; style: StyleProp<ViewStyle>; children: ReactNode }) {
  return (
    <Animated.View
      pointerEvents={hidden ? 'none' : 'box-none'}
      accessibilityElementsHidden={hidden}
      importantForAccessibility={hidden ? 'no-hide-descendants' : 'auto'}
      style={[{ position: 'absolute', top: 0, bottom: 0, justifyContent: 'center' }, style]}
    >
      {children}
    </Animated.View>
  );
}

const TITLE = { includeFontPadding: false, fontFamily: 'SourceSans3_700Bold', fontSize: 15, letterSpacing: 1, color: '#106E6D' } as const;

/**
 * Slim header shared by the main tabs, rendered by the pager next to the tab
 * bar. It sits above the pages, so it stays put while they scroll and does not
 * jump when switching tabs; the pages must not add the top safe-area inset again.
 *
 * Each page's title and action come from its screen options (`title`,
 * `headerAction`) and follow the pager scroll via `pageFade`. Actions are laid
 * out against the account button, which never moves.
 */
export function AppHeader({ state, descriptors, position }: MaterialTopTabBarProps) {
  const index = state.index;
  // The pages the current (or last) transition runs between.
  const [transition, setTransition] = useState({ from: index, to: index });
  if (transition.to !== index) setTransition({ from: transition.to, to: index });
  const { from, to } = transition;
  const count = state.routes.length;
  const fades = useMemo(
    () => Array.from({ length: count }, (_, page) => pageFade(position, page, from, to)),
    [position, from, to, count],
  );
  return (
    <SafeAreaView edges={['top']}>
      {/* Hairline separates the bar from content scrolling underneath. */}
      <View className="h-[52px] flex-row items-center justify-between border-b pl-5 pr-2" style={{ borderBottomColor: 'rgba(27,42,42,0.1)' }}>
        <View className="h-7 flex-1">
          {state.routes.map((route, page) => {
            const options = descriptors[route.key].options as PagerScreenOptions;
            return (
              <PageSlot key={route.key} hidden={page !== to} style={[{ left: 0, right: 0 }, fades[page]]}>
                <Text accessibilityRole="header" numberOfLines={1} maxFontSizeMultiplier={1.3} style={TITLE}>
                  {options.title ?? route.name}
                </Text>
              </PageSlot>
            );
          })}
        </View>
        {/* Spans the bar up to the account button so actions stay inside their parent's
            bounds (Android only delivers touches there); taps pass through elsewhere. */}
        <View pointerEvents="box-none" style={{ position: 'absolute', left: 0, top: 0, bottom: 0, right: 56 }}>
          {state.routes.map((route, page) => {
            const action = (descriptors[route.key].options as PagerScreenOptions).headerAction;
            if (!action) return null;
            return <PageSlot key={route.key} hidden={page !== to} style={[{ right: 0 }, fades[page]]}>{action()}</PageSlot>;
          })}
        </View>
        <IconButton accessibilityLabel="Account openen" onPress={() => router.push('/(tabs)/account')}>
          <UserRound size={22} color={HEADER_ICON} />
        </IconButton>
      </View>
    </SafeAreaView>
  );
}

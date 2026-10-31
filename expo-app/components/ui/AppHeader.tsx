import { useEffect, useRef, useState, type ReactNode } from 'react';
import { View, Text, Animated, Platform, type StyleProp, type ViewStyle } from 'react-native';
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
// A rest report this soon after a page change is the previous move's.
const STALE_REST_MS = 100;

/**
 * Which titles (and header actions) take part in the current page change, in
 * plain numbers. A page with `on[page]` is visible while the pager is within
 * `width[page]` of it, fading out linearly towards that distance; other pages
 * stay hidden (opacity = on × (1 − |position − page| / width), clamped). Only
 * these numbers change on a page change, never the pager position they are
 * compared with, so no JS knowledge of where the pager is (which it cannot
 * read) is needed.
 */
type Transition = { to: number; on: number[]; width: number[] };

function settled(to: number, count: number): Transition {
  return { to, on: Array.from({ length: count }, (_, page) => (page === to ? 1 : 0)), width: Array<number>(count).fill(0.5) };
}

/**
 * The change towards page `to` after `previous`. The page left behind fades out
 * by halfway, the new page fades in from halfway, so the two never overlap and
 * pages passed on the way stay hidden.
 *
 * When the change `interrupted` a running one (the pager was still moving), it
 * is somewhere between the pages of the earlier changes, so all of those keep
 * (at most) the windows they had: whatever is visible keeps fading from where
 * it is instead of jumping, and is gone by the time the new page is reached.
 */
function nextTransition(previous: Transition, to: number, interrupted: boolean): Transition {
  const leaving = previous.to;
  const half = Math.max(Math.abs(to - leaving) / 2, 0.5);
  const involved = (page: number) => interrupted && previous.on[page] === 1;
  const on = previous.on.map((_, page) => (page === to || page === leaving || involved(page) ? 1 : 0));
  const width = previous.width.map((value, page) => {
    if (page === to) return involved(page) ? Math.max(value, half) : half;
    // Never wider than the distance to the new page, so nothing is left visible there.
    if (involved(page)) return Math.min(Math.max(value, half), Math.max(Math.abs(to - page), 0.5));
    return page === leaving ? half : value;
  });
  return { to, on, width };
}

/**
 * Native animation graph for the header slots, built once. A page change only
 * sets the `on` and `width` values (`apply`); styles never change, so the
 * native driver keeps full control even when a change interrupts a running one.
 */
function createSlotAnimations(position: Position, count: number, index: number) {
  const initial = settled(index, count);
  const on = initial.on.map(value => new Animated.Value(value));
  const width = initial.width.map(value => new Animated.Value(value));
  const styles = on.map((visible, page) => {
    // Signed distance from this page in multiples of its window: 0 here, ±1 at the window edge.
    const offset = Animated.divide(Animated.add(position, -page), width[page]);
    const distance = offset.interpolate({ inputRange: [-1, 0, 1], outputRange: [1, 0, 1], extrapolate: 'clamp' });
    return {
      opacity: Animated.multiply(visible, distance.interpolate({ inputRange: [0, 1], outputRange: [1, 0], extrapolate: 'clamp' })),
      transform: [{ translateX: offset.interpolate({ inputRange: [-1, 0, 1], outputRange: [SLIDE, 0, -SLIDE], extrapolate: 'clamp' }) }],
    };
  });
  const apply = (t: Transition) => {
    t.on.forEach((value, page) => on[page].setValue(value));
    t.width.forEach((value, page) => width[page].setValue(value));
  };
  return { styles, apply, initial };
}

/** One page's title or action, stacked with the other pages' and faded by `createSlotAnimations`. */
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
 * `headerAction`) and follow the pager scroll via `createSlotAnimations`. Actions are laid
 * out against the account button, which never moves.
 */
export function AppHeader({ state, descriptors, position }: MaterialTopTabBarProps) {
  const index = state.index;
  const count = state.routes.length;
  // The pager's position node and page count are fixed for the header's lifetime.
  const [{ styles, apply, initial }] = useState(() => createSlotAnimations(position, count, index));
  const transition = useRef(initial);
  // Whether the pager is still moving towards the last page change. Native pagers
  // report coming to rest (swipeEnd, sent to every page's listeners); a report right
  // after a new change belongs to the previous move. On web the pager animates in JS,
  // so the position itself shows when it reaches the page.
  const moving = useRef(false);
  const changedAt = useRef(0);
  const restNavigation = descriptors[state.routes[0].key].navigation;
  useEffect(() => restNavigation.addListener('swipeEnd', () => {
    if (Date.now() - changedAt.current > STALE_REST_MS) moving.current = false;
  }), [restNavigation]);
  useEffect(() => {
    if (Platform.OS !== 'web') return undefined;
    const id = position.addListener(({ value }) => {
      if (Math.abs(value - transition.current.to) < 1e-3) moving.current = false;
    });
    return () => position.removeListener(id);
  }, [position]);
  useEffect(() => {
    if (transition.current.to === index) return;
    transition.current = nextTransition(transition.current, index, moving.current);
    moving.current = true;
    changedAt.current = Date.now();
    apply(transition.current);
  }, [index, apply]);
  const to = index;
  return (
    <SafeAreaView edges={['top']}>
      {/* Hairline separates the bar from content scrolling underneath. */}
      <View className="h-[52px] flex-row items-center justify-between border-b pl-5 pr-2" style={{ borderBottomColor: 'rgba(27,42,42,0.1)' }}>
        <View className="h-7 flex-1">
          {state.routes.map((route, page) => {
            const options = descriptors[route.key].options as PagerScreenOptions;
            return (
              <PageSlot key={route.key} hidden={page !== to} style={[{ left: 0, right: 0 }, styles[page]]}>
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
            return <PageSlot key={route.key} hidden={page !== to} style={[{ right: 0 }, styles[page]]}>{action()}</PageSlot>;
          })}
        </View>
        <IconButton accessibilityLabel="Account openen" hitSlop={0} onPress={() => router.push('/(tabs)/account')}>
          <UserRound size={22} color={HEADER_ICON} />
        </IconButton>
      </View>
    </SafeAreaView>
  );
}

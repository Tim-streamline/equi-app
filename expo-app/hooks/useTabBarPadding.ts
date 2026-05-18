import { useSafeAreaInsets } from 'react-native-safe-area-context';

export const TAB_BAR_BASE_HEIGHT = 60;

export function useTabBarPadding(extra = 16) {
  const insets = useSafeAreaInsets();
  return TAB_BAR_BASE_HEIGHT + insets.bottom + extra;
}

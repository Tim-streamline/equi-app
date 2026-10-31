import { useEffect, type ReactNode } from 'react';
import { useNavigation } from 'expo-router';

/** Options of the main pager pages; the shared header reads them from the navigator. */
export type PagerScreenOptions = {
  /** Page name in the header. */
  title?: string;
  /** Page-specific control in the header's right slot. */
  headerAction?: () => ReactNode;
};

/**
 * Shows `action` in the shared header while this pager page is visible, for
 * actions that depend on page state (static ones go in the layout's screen
 * options). Pass an element memoized with `useMemo`: it is re-published only
 * when that element changes, and removed again when the page unmounts.
 */
export function useHeaderAction(action: ReactNode) {
  const navigation = useNavigation();
  useEffect(() => {
    const options: PagerScreenOptions = { headerAction: action == null ? undefined : () => action };
    navigation.setOptions(options);
  }, [navigation, action]);
  useEffect(() => () => {
    const options: PagerScreenOptions = { headerAction: undefined };
    navigation.setOptions(options);
  }, [navigation]);
}

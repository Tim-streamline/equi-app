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
 * actions that depend on page state. Pass an element memoized with `useMemo`:
 * it is re-published only when that element changes, and removed again when
 * the page unmounts. Pages whose action is static set it in the layout's
 * screen options instead (this hook would clear that on unmount).
 */
export function useHeaderAction(action: ReactNode) {
  const navigation = useNavigation();
  useEffect(() => {
    const set = (headerAction: PagerScreenOptions['headerAction']) => navigation.setOptions({ headerAction } satisfies PagerScreenOptions);
    set(action == null ? undefined : () => action);
    return () => set(undefined);
  }, [navigation, action]);
}

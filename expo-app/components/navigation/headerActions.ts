import { useEffect, type DependencyList, type ReactNode } from 'react';
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
 * options). Like `useEffect`, it is only re-published when `deps` change.
 */
export function useHeaderAction(action: (() => ReactNode) | undefined, deps: DependencyList) {
  const navigation = useNavigation();
  useEffect(() => {
    const options: PagerScreenOptions = { headerAction: action };
    navigation.setOptions(options);
    // `action` is a fresh closure every render; `deps` says when it really changed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [navigation, ...deps]);
}

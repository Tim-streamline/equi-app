import { useEffect, useSyncExternalStore, type ReactNode } from 'react';

/**
 * Page actions for the right slot of the shared header, keyed by pager route.
 * Pages hand over their own action; only the header subscribes, so updating an
 * action re-renders the header and never the pages themselves.
 */
let actions: Readonly<Record<string, ReactNode>> = {};
const listeners = new Set<() => void>();

function update(route: string, node: ReactNode | undefined) {
  const next = { ...actions };
  if (node === undefined) delete next[route];
  else next[route] = node;
  actions = next;
  listeners.forEach(listener => listener());
}

/** Show `node` in the header while `route` is the visible page. */
export function useHeaderAction(route: string, node: ReactNode) {
  useEffect(() => update(route, node), [route, node]);
  useEffect(() => () => update(route, undefined), [route]);
}

export function useHeaderActions() {
  return useSyncExternalStore(
    listener => { listeners.add(listener); return () => { listeners.delete(listener); }; },
    () => actions,
  );
}

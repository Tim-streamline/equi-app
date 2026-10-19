import type { RefreshProtection } from './refresh-safety-core';

// Native clients do not participate in the browser refresh flow.
export const registerRefreshProtection = (_protection: RefreshProtection) => () => {};
export const refreshProtectionChanged = () => {};

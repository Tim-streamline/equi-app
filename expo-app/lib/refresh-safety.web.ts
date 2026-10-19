import { RefreshSafety } from './refresh-safety-core';

export const refreshSafety = new RefreshSafety();
export const registerRefreshProtection = refreshSafety.register;
export const refreshProtectionChanged = refreshSafety.changed;

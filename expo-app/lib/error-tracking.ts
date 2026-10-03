import * as Sentry from '@sentry/react-native';
import * as Updates from 'expo-updates';
import { BETTER_STACK_ANDROID_DSN, errorTrackingOptions } from './error-tracking-options';

Sentry.init({
  ...errorTrackingOptions(
    process.env.EXPO_PUBLIC_BETTER_STACK_DSN ?? BETTER_STACK_ANDROID_DSN,
    process.env.EXPO_PUBLIC_BETTER_STACK_ENVIRONMENT ?? (__DEV__ ? 'development' : 'production'),
    process.env.EXPO_PUBLIC_BETTER_STACK_RELEASE,
    !__DEV__ || process.env.EXPO_PUBLIC_BETTER_STACK_ENABLED === 'true',
  ),
  enableNative: true,
  enableNativeCrashHandling: true,
  enableAutoPerformanceTracing: false,
  enableAutoSessionTracking: false,
  replaysSessionSampleRate: 0,
  replaysOnErrorSampleRate: 0,
  attachScreenshot: false,
  attachViewHierarchy: false,
});
Sentry.setTag('component', 'android');
if (Updates.updateId) Sentry.setTag('expo-update-id', Updates.updateId);
if (Updates.runtimeVersion) Sentry.setTag('expo-runtime-version', Updates.runtimeVersion);
if (Updates.channel) Sentry.setTag('expo-channel', Updates.channel);

export const captureException = Sentry.captureException;
export const wrapRoot = Sentry.wrap;

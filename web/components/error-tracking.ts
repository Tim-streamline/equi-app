import * as Sentry from '@sentry/react';
import { BETTER_STACK_WEB_DSN, errorTrackingOptions } from '../../expo-app/lib/error-tracking-options';

Sentry.init(errorTrackingOptions(
  process.env.EXPO_PUBLIC_BETTER_STACK_DSN ?? BETTER_STACK_WEB_DSN,
  process.env.EXPO_PUBLIC_BETTER_STACK_ENVIRONMENT ?? (__DEV__ ? 'development' : 'production'),
  process.env.EXPO_PUBLIC_BETTER_STACK_RELEASE,
  !__DEV__ || process.env.EXPO_PUBLIC_BETTER_STACK_ENABLED === 'true',
));
Sentry.setTag('component', 'web');

export const captureException = Sentry.captureException;

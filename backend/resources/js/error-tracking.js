import * as Sentry from '@sentry/browser';
import { BETTER_STACK_WEB_DSN, errorTrackingOptions } from '../../../expo-app/lib/error-tracking-options';

Sentry.init(errorTrackingOptions(
    import.meta.env.VITE_BETTER_STACK_DSN ?? BETTER_STACK_WEB_DSN,
    import.meta.env.VITE_BETTER_STACK_ENVIRONMENT ?? (import.meta.env.DEV ? 'development' : 'production'),
    import.meta.env.VITE_BETTER_STACK_RELEASE,
    !import.meta.env.DEV || import.meta.env.VITE_BETTER_STACK_ENABLED === 'true',
));
Sentry.setTag('component', 'admin');

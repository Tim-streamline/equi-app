import '../global.css';
import './browser-alerts';
import { ErrorBoundary } from '@sentry/react';
import { Stack, router, usePathname, useRootNavigationState } from 'expo-router';
import { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { View } from 'react-native';
import { useFonts, SourceSans3_400Regular, SourceSans3_500Medium, SourceSans3_600SemiBold, SourceSans3_700Bold, SourceSans3_400Regular_Italic, SourceSans3_600SemiBold_Italic } from '@expo-google-fonts/source-sans-3';
import { DbProvider, useDb } from '../db/provider';
import { loginDestination } from '../db/login-destination';
import { IntakeProvider } from '@/lib/intake/store';
import { IntakeSchemaProvider } from '@/lib/intake/schema-provider';
import { LibraryContentSync } from '@/components/library/LibraryContentSync';

export default function RootLayout() {
  const [loaded, error] = useFonts({ SourceSans3_400Regular, SourceSans3_500Medium, SourceSans3_600SemiBold, SourceSans3_700Bold, SourceSans3_400Regular_Italic, SourceSans3_600SemiBold_Italic });
  if (!loaded && !error) return null;
  return <ErrorBoundary><GestureHandlerRootView style={{ flex: 1 }}>
    <SafeAreaProvider>
      <View style={{ flex: 1, width: '100%', maxWidth: 1100, alignSelf: 'center', backgroundColor: '#FBF8F3' }}>
        <DbProvider><IntakeSchemaProvider><IntakeProvider>
          <LibraryContentSync />
          <Navigation />
        </IntakeProvider></IntakeSchemaProvider></DbProvider>
      </View>
    </SafeAreaProvider>
  </GestureHandlerRootView></ErrorBoundary>;
}

function Navigation() {
  const { isLoggedIn } = useDb();
  const pathname = usePathname();
  const navigation = useRootNavigationState();
  useEffect(() => {
    if (navigation?.key && isLoggedIn && ['/onboarding/welcome', '/onboarding/register'].includes(pathname)) {
      router.replace(loginDestination(window.location.search) as any);
    }
    if (navigation?.key && !isLoggedIn && pathname !== '/' && !pathname.startsWith('/onboarding')) {
      router.replace({ pathname: '/onboarding/welcome', params: { returnTo: window.location.pathname + window.location.search } });
    }
  }, [isLoggedIn, pathname, navigation?.key]);
  return <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#FBF8F3' } }} />;
}

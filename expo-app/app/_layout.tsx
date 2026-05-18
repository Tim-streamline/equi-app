import '../global.css';

import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import * as NavigationBar from 'expo-navigation-bar';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { Platform, View } from 'react-native';
import { useEffect } from 'react';
import {
  useFonts,
  SourceSans3_400Regular,
  SourceSans3_500Medium,
  SourceSans3_600SemiBold,
  SourceSans3_700Bold,
  SourceSans3_400Regular_Italic,
  SourceSans3_600SemiBold_Italic,
} from '@expo-google-fonts/source-sans-3';

SplashScreen.preventAutoHideAsync().catch(() => {});

export default function RootLayout() {
  const [loaded] = useFonts({
    SourceSans3_400Regular,
    SourceSans3_500Medium,
    SourceSans3_600SemiBold,
    SourceSans3_700Bold,
    SourceSans3_400Regular_Italic,
    SourceSans3_600SemiBold_Italic,
  });

  useEffect(() => {
    if (loaded) SplashScreen.hideAsync().catch(() => {});
  }, [loaded]);

  useEffect(() => {
    if (Platform.OS === 'android') {
      NavigationBar.setButtonStyleAsync('dark').catch(() => {});
    }
  }, []);

  if (!loaded) return null;

  return (
    <GestureHandlerRootView style={{ flex: 1, backgroundColor: '#FBF8F3' }}>
      <SafeAreaProvider>
        <View style={{ flex: 1, backgroundColor: '#FBF8F3' }}>
          <StatusBar style="dark" />
          <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#FBF8F3' } }}>
            <Stack.Screen name="index" />
            <Stack.Screen name="onboarding" />
            <Stack.Screen name="(tabs)" />
            <Stack.Screen
              name="nova-chat"
              options={{ presentation: 'transparentModal', animation: 'fade' }}
            />
          </Stack>
        </View>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

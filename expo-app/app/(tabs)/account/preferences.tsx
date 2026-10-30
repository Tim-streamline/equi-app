import { BrowserPushSettings } from '@/components/notifications/BrowserPushSettings';
import { ActivityIndicator, Alert, ScrollView, View } from 'react-native';
import { SwitchRow } from '@/components/ui/SwitchRow';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { SubHeader } from '@/components/ui/SubHeader';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useHomePreferences } from '@/hooks/useHomePreferences';

export default function PreferencesScreen() {
  const padBottom = useTabBarPadding();
  const { preferences, ready, saving, save } = useHomePreferences();
  return (
    <SafeAreaView edges={['top']} className="flex-1 bg-canvas">
      <SubHeader title="Voorkeuren" onBack={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/account')} />
      <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: padBottom }}>
        {!ready ? <ActivityIndicator color="#18BAB0" /> : (
          <View className="rounded-[22px] bg-white p-5">
            <SwitchRow
              label="Seizoenstips op Home"
              description="Toon praktische tips die passen bij het seizoen."
              value={preferences.seasonalTipsEnabled}
              disabled={saving}
              onChange={(enabled) => void save({ type: 'set-enabled', enabled }).catch(() =>
                Alert.alert('Voorkeur niet opgeslagen', 'Probeer het opnieuw.'),
              )}
            />
          </View>
        )}
        <BrowserPushSettings />
      </ScrollView>
    </SafeAreaView>
  );
}

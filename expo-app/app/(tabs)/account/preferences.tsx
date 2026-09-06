import { ActivityIndicator, Alert, ScrollView, Switch, Text, View } from 'react-native';
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
          <View className="flex-row items-center gap-3 rounded-[22px] bg-white p-5">
            <View className="flex-1">
              <Text className="font-semi text-[16px] text-ink">Seizoenstips op Home</Text>
              <Text className="mt-1 text-[13px] leading-[20px] text-ink-70">
                Toon praktische tips die passen bij het seizoen.
              </Text>
              <Text className="mt-2 font-semi text-[12px] text-mint-700">
                {preferences.seasonalTipsEnabled ? 'Aan' : 'Uit'}
              </Text>
            </View>
            <Switch
              accessibilityLabel="Seizoenstips op Home"
              value={preferences.seasonalTipsEnabled}
              disabled={saving}
              trackColor={{ false: '#D8DEDA', true: '#18BAB0' }}
              thumbColor="#FFFFFF"
              onValueChange={(enabled) => void save({ type: 'set-enabled', enabled }).catch(() =>
                Alert.alert('Voorkeur niet opgeslagen', 'Probeer het opnieuw.'),
              )}
            />
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

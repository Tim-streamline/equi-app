import { useEffect, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { Redirect } from 'expo-router';
import AsyncStorage from '@react-native-async-storage/async-storage';

const ONBOARDED_KEY = 'equinova:onboarded';

export default function Index() {
  const [target, setTarget] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const v = await AsyncStorage.getItem(ONBOARDED_KEY);
        setTarget(v === '1' ? '/(tabs)/home' : '/onboarding/welcome');
      } catch {
        setTarget('/onboarding/welcome');
      }
    })();
  }, []);

  if (!target) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FBF8F3' }}>
        <ActivityIndicator color="#18BAB0" />
      </View>
    );
  }

  return <Redirect href={target as any} />;
}

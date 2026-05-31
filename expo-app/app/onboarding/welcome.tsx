// Welcome + login screen. Bypasses the old "screenCopy" table which used to
// live in TinyBase — once we moved to PowerSync the brand strings became
// hard-coded again. Auth credentials are sent to Laravel which mints a
// PowerSync JWT; the provider then connects and starts syncing.

import { useState } from 'react';
import { View, Text, Image, TextInput, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { ArrowRight } from 'lucide-react-native';
import { Button } from '@/components/ui/Button';
import { useDb } from '@/db/provider';

const DEFAULT_EMAIL = 'marit@voorbeeld.nl';
const DEFAULT_PASSWORD = 'password';

export default function WelcomeScreen() {
  const { login } = useDb();
  const [email, setEmail] = useState(DEFAULT_EMAIL);
  const [password, setPassword] = useState(DEFAULT_PASSWORD);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setError(null);
    setBusy(true);
    try {
      await login(email.trim(), password);
      router.replace('/(tabs)/(pager)/home');
    } catch (err: any) {
      setError(err?.message ?? 'Login failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <View className="flex-1 bg-teal-900">
      <StatusBar style="light" />
      <SafeAreaView style={{ flex: 1 }}>
        <View className="flex-1 justify-between px-7 pb-7 pt-6">
          <View className="flex-row items-center gap-2.5">
            <View className="h-9 w-9 items-center justify-center rounded-xl bg-mint-500">
              <Image
                source={require('@/assets/images/logo-horse-white.png')}
                style={{ width: 22, height: 22, resizeMode: 'contain' }}
              />
            </View>
            <View>
              <Text className="font-bold text-white" style={{ fontSize: 18, letterSpacing: 0.5 }}>
                EquiNova
              </Text>
              <Text
                className="font-semi text-mint-200"
                style={{ fontSize: 10, letterSpacing: 0.6, textTransform: 'uppercase' }}
              >
                by De Paardentherapeut
              </Text>
            </View>
          </View>

          <View>
            <Text className="font-semi-italic text-mint-200 mb-3" style={{ fontSize: 14 }}>
              Paardengezondheid van de toekomst.
            </Text>
            <Text className="font-bold text-white mb-5" style={{ fontSize: 38, lineHeight: 42 }}>
              Ken je paard.{'\n'}Van binnenuit.
            </Text>

            <View className="gap-2 mb-3">
              <TextInput
                value={email}
                onChangeText={setEmail}
                placeholder="Email"
                placeholderTextColor="rgba(255,255,255,0.5)"
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                className="rounded-pill bg-white/10 px-4 py-3 font-sans text-[14px] text-white"
              />
              <TextInput
                value={password}
                onChangeText={setPassword}
                placeholder="Wachtwoord"
                placeholderTextColor="rgba(255,255,255,0.5)"
                secureTextEntry
                className="rounded-pill bg-white/10 px-4 py-3 font-sans text-[14px] text-white"
              />
            </View>
            {error ? (
              <Text className="font-semi text-[12px]" style={{ color: '#FCA5A5' }}>
                {error}
              </Text>
            ) : null}
          </View>

          <View className="gap-2.5">
            <Button
              title={busy ? 'Bezig met inloggen…' : 'Inloggen'}
              variant="primary"
              disabled={busy}
              onPress={submit}
              trailing={busy ? <ActivityIndicator color="#fff" /> : <ArrowRight size={18} color="#fff" />}
            />
          </View>
        </View>
      </SafeAreaView>
    </View>
  );
}

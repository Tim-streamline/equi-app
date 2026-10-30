import { BRAND_NAME } from '@/constants/brand';
import { KeyboardScrollView as ScrollView, KeyboardViewport as KeyboardAvoidingView } from '@/components/ui/KeyboardForm';
import { Field } from '@/components/ui/Field';
// Welcome + login screen. Bypasses the old "screenCopy" table which used to
// live in TinyBase — once we moved to PowerSync the brand strings became
// hard-coded again. Auth credentials are sent to Laravel which mints a
// PowerSync JWT; the provider then connects and starts syncing.

import { useState } from 'react';
import { View, Text, Image, Pressable, ActivityIndicator, Platform,  } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { ArrowRight } from 'lucide-react-native';
import { Button, PRIMARY_INK } from '@/components/ui/Button';
import { useDb } from '@/db/provider';

export default function WelcomeScreen() {
  const { communityPost, passwordReset } = useLocalSearchParams<{ communityPost?: string; passwordReset?: string }>();
  const { login } = useDb();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setError(null);
    setBusy(true);
    try {
      await login(email.trim(), password);
      // The web database changes on login; navigate after its route tree mounts.
      if (Platform.OS === 'web') return;
      if (communityPost && /^[0-9a-f-]{36}$/i.test(communityPost)) {
        router.replace({ pathname: '/(tabs)/community/thread/[id]', params: { id: communityPost } });
      } else router.replace('/(tabs)/(pager)/home');
    } catch (err: any) {
      setError(err?.message ?? 'Login failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <View className="flex-1 bg-teal-900">
      <StatusBar style="light" />
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      >
        <SafeAreaView style={{ flex: 1 }}>
          <ScrollView
            style={{ flex: 1 }}
            contentContainerClassName="gap-6 px-7 pb-7 pt-6"
            contentContainerStyle={{
              flexGrow: 1,
              justifyContent: 'space-between',
            }}
            keyboardShouldPersistTaps="handled"
          >
            <View className="flex-row items-center gap-2.5">
              <View className="h-9 w-9 items-center justify-center rounded-xl bg-mint-500">
                <Image
                  source={require('@/assets/images/logo-horse-white.png')}
                  style={{ width: 22, height: 22, resizeMode: 'contain' }}
                />
              </View>
              <View>
                <Text className="font-bold text-white" style={{ fontSize: 18, letterSpacing: 0.5 }}>
                  {BRAND_NAME}
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
              <Text className="font-bold text-white mb-5" style={{ fontSize: 28, lineHeight: 34 }}>
                Welkom bij {BRAND_NAME}!
              </Text>

              <Field
                tone="deep"
                label="E-mailadres"
                value={email}
                onChangeText={setEmail}
                autoComplete="email"
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
              />
              <Field
                tone="deep"
                label="Wachtwoord"
                value={password}
                onChangeText={setPassword}
                autoComplete="current-password"
                secureTextEntry
                passwordToggle
                onSubmitEditing={() => { if (!busy) void submit(); }}
              />
              <Pressable accessibilityRole="link" onPress={() => router.push('/onboarding/forgot-password')} className="min-h-11 justify-center self-end">
                <Text className="font-semi text-[14px] text-mint-200">Wachtwoord vergeten?</Text>
              </Pressable>
              {passwordReset === '1' ? <Text accessibilityRole="alert" className="mt-2 text-[14px] text-mint-200">Je wachtwoord is gewijzigd. Je kunt nu inloggen.</Text> : null}
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
                trailing={busy ? <ActivityIndicator color={PRIMARY_INK} /> : <ArrowRight size={18} color={PRIMARY_INK} />}
              />
              <Button title="Nieuw hier? Maak een account" variant="text" textClassName="text-white" disabled={busy} onPress={() => router.push('/onboarding/register')} />
            </View>
          </ScrollView>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </View>
  );
}

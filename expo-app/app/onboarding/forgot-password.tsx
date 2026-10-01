import { useState } from 'react';
import { View, Text, Platform } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { KeyboardViewport, KeyboardScrollView, KeyboardTextInput } from '@/components/ui/KeyboardForm';
import { Button } from '@/components/ui/Button';
import { requestPasswordReset } from '@/db/auth';

export default function ForgotPasswordScreen() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  async function submit() {
    if (busy) return;
    setError('');
    if (!email.trim()) { setError('Vul je e-mailadres in.'); return; }
    setBusy(true);
    try { setMessage((await requestPasswordReset(email)).message); }
    catch (failure) { setError(failure instanceof Error ? failure.message : 'Probeer opnieuw.'); }
    finally { setBusy(false); }
  }
  return <View className="flex-1 bg-teal-900">
    <StatusBar style="light" />
    <KeyboardViewport style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <SafeAreaView style={{ flex: 1 }}>
        <KeyboardScrollView contentContainerStyle={{ flexGrow: 1, justifyContent: 'center' }} contentContainerClassName="gap-5 px-7 py-8" keyboardShouldPersistTaps="handled">
          <Text className="font-bold text-[28px] text-white">Wachtwoord vergeten?</Text>
          <Text className="text-[16px] text-mint-200">Vul je geregistreerde e-mailadres in. We sturen je een link om een nieuw wachtwoord in te stellen.</Text>
          {message ? <Text accessibilityRole="alert" className="text-[16px] text-white">{message}</Text> : <>
            <KeyboardTextInput value={email} onChangeText={setEmail} placeholder="e-mailadres" accessibilityLabel="E-mailadres" autoComplete="email" autoCapitalize="none" autoCorrect={false} keyboardType="email-address" placeholderTextColor="rgba(255,255,255,0.5)" className="rounded-pill bg-white/10 px-4 py-3 font-sans text-[14px] text-white" onSubmitEditing={() => { void submit(); }} />
            {error ? <Text accessibilityRole="alert" className="text-[14px] text-red-300">{error}</Text> : null}
            <Button title={busy ? 'Bezig met versturen…' : 'Verstuur resetlink'} disabled={busy} onPress={submit} />
          </>}
          <Button title="Terug naar inloggen" variant="text" textClassName="text-white" onPress={() => router.replace('/onboarding/welcome')} />
        </KeyboardScrollView>
      </SafeAreaView>
    </KeyboardViewport>
  </View>;
}

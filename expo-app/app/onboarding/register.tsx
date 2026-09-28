import { useRef, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { Redirect, router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { SubHeader } from '@/components/ui/SubHeader';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useDb } from '@/db/provider';

export default function RegisterScreen() {
  const { register, isLoggedIn } = useDb();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submitting = useRef(false);
  async function submit() {
    if (submitting.current) return;
    setError('');
    if (!name.trim() || !email.trim()) { setError('Vul je naam en e-mailadres in.'); return; }
    if (password.length < 8) { setError('Gebruik een wachtwoord van minimaal 8 tekens.'); return; }
    if (password !== confirmation) { setError('De wachtwoorden komen niet overeen.'); return; }
    submitting.current = true; setBusy(true);
    try {
      await register({ name: name.trim(), email: email.trim(), password, password_confirmation: confirmation });
      if (Platform.OS !== 'web') router.replace('/onboarding/add-horse');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Registreren is niet gelukt. Probeer opnieuw.');
    } finally { submitting.current = false; setBusy(false); }
  }
  if (isLoggedIn) return <Redirect href="/onboarding/add-horse" />;
  return <SafeAreaView className="flex-1 bg-canvas">
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <SubHeader title="Account aanmaken" onBack={() => { if (!busy) router.back(); }} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 24, paddingBottom: 40, flexGrow: 1 }}>
        <Text className="mb-2 font-bold text-[28px] text-ink">Welkom bij EquiNova.</Text>
        <Text className="mb-6 text-[15px] text-ink-50">Maak je account aan. Daarna kun je meteen je paard toevoegen, of dat later doen.</Text>
        <Field label="Naam" accessibilityLabel="Naam" value={name} onChangeText={setName} autoComplete="name" editable={!busy} maxLength={255} />
        <Field label="E-mailadres" accessibilityLabel="E-mailadres" value={email} onChangeText={setEmail} autoComplete="email" autoCapitalize="none" autoCorrect={false} keyboardType="email-address" editable={!busy} maxLength={255} />
        <Field label="Wachtwoord" accessibilityLabel="Wachtwoord" value={password} onChangeText={setPassword} autoComplete="new-password" secureTextEntry editable={!busy} maxLength={128} />
        <Text className="mb-4 text-[13px] text-ink-50">Gebruik minimaal 8 tekens.</Text>
        <Field label="Herhaal wachtwoord" accessibilityLabel="Herhaal wachtwoord" value={confirmation} onChangeText={setConfirmation} autoComplete="new-password" secureTextEntry editable={!busy} maxLength={128} onSubmitEditing={() => void submit()} />
        {!!error && <Text accessibilityRole="alert" className="mb-4 text-[14px] text-red-700">{error}</Text>}
        <View className="mt-3 gap-3">
          <Button title={busy ? 'Account aanmaken…' : 'Account aanmaken'} onPress={() => void submit()} disabled={busy} trailing={busy ? <ActivityIndicator color="#fff" /> : undefined} />
          <Button title="Ik heb al een account" variant="text" disabled={busy} onPress={() => router.replace('/onboarding/welcome')} />
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

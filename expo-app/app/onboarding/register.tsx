import { KeyboardScrollView as ScrollView, KeyboardViewport as KeyboardAvoidingView } from '@/components/ui/KeyboardForm';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Platform, Text, View } from 'react-native';
import { Redirect, router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { SubHeader } from '@/components/ui/SubHeader';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useDb } from '@/db/provider';
import { checkRegistration, type RegistrationChallenge } from '@/db/auth';
import { waitForRegistration } from '@/lib/registration-wait';

export default function RegisterScreen() {
  const { register, completeRegistration, isLoggedIn } = useDb();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [waitingError, setWaitingError] = useState('');
  const [challenge, setChallenge] = useState<RegistrationChallenge | null>(null);
  const [finishing, setFinishing] = useState(false);
  const [expired, setExpired] = useState(false);
  const completeRef = useRef(completeRegistration);
  completeRef.current = completeRegistration;
  const submitting = useRef(false);
  const waitingController = useRef<AbortController | null>(null);
  async function submit() {
    if (submitting.current) return;
    setError('');
    setWaitingError('');
    if (!name.trim() || !email.trim()) { setError('Vul je naam en e-mailadres in.'); return; }
    if (password.length < 8) { setError('Gebruik een wachtwoord van minimaal 8 tekens.'); return; }
    if (password !== confirmation) { setError('De wachtwoorden komen niet overeen.'); return; }
    waitingController.current?.abort();
    submitting.current = true; setBusy(true);
    try {
      const pending = await register({ name: name.trim(), email: email.trim(), password, password_confirmation: confirmation });
      setChallenge(pending);
      setExpired(false);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Registreren is niet gelukt. Probeer opnieuw.');
      // Resume waiting even when a failed resend was batched into the same render.
      if (challenge) setChallenge({ ...challenge });
    } finally { submitting.current = false; setBusy(false); }
  }
  useEffect(() => {
    if (!challenge || busy) return;
    const controller = new AbortController();
    waitingController.current = controller;
    const input = { registration_token: challenge.registration_token };
    void waitForRegistration({
      signal: controller.signal,
      check: signal => checkRegistration(input, signal),
      onError: setWaitingError,
      finish: async () => {
        submitting.current = true;
        setFinishing(true);
        try { await completeRef.current(input, password); }
        finally {
          submitting.current = false;
          if (!controller.signal.aborted) setFinishing(false);
        }
      },
    }).then(result => {
      if (controller.signal.aborted) return;
      if (result === 'completed' && Platform.OS !== 'web') router.replace('/onboarding/add-horse');
      if (result === 'expired') {
        setExpired(true);
        setWaitingError('Deze bevestigingslink is verlopen of vervangen. Vraag een nieuwe bevestigingsmail aan.');
      }
    });
    return () => controller.abort();
  }, [challenge, password, busy]);
  const locked = busy || finishing;
  if (isLoggedIn) return <Redirect href="/onboarding/add-horse" />;
  return <SafeAreaView className="flex-1 bg-canvas">
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <SubHeader title="Account aanmaken" onBack={() => { if (!locked) { waitingController.current?.abort(); router.back(); } }} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 24, paddingBottom: 40, flexGrow: 1 }}>
        {challenge ? <>
          <Text className="mb-2 font-bold text-[28px] text-ink">Bevestig je e-mailadres</Text>
          <Text className="mb-6 text-[15px] text-ink-50">We hebben een bevestigingsmail gestuurd naar {challenge.email}. Klik op de link in de e-mail. Dit scherm gaat automatisch verder en logt je in zodra je e-mailadres is bevestigd, ook als je de link op een ander apparaat opent.</Text>
          <Text className="mb-4 text-[13px] text-ink-50">Laat dit scherm openstaan. De link is 15 minuten geldig. Controleer ook je spammap.</Text>
          {!expired && <View className="mb-4 flex-row items-center gap-3" accessibilityRole="progressbar" accessibilityLabel={finishing ? 'Je wordt ingelogd' : 'Wachten op e-mailbevestiging'}>
            <ActivityIndicator color="#127A79" />
            <Text className="text-ink-50">{finishing ? 'Je wordt ingelogd…' : 'Wachten op bevestiging…'}</Text>
          </View>}
        </> : <>
          <Text className="mb-2 font-bold text-[28px] text-ink">Welkom bij Equi App.</Text>
          <Text className="mb-3 font-bold text-[15px] text-ink">Maak je account aan en ontdek wat EquiApp voor jou en je paard kan betekenen.</Text>
          <Text className="mb-6 text-[15px] text-ink-50">We controleren eerst je e-mailadres via een link in de bevestigingsmail. Daarna kun je je paard toevoegen, of dat later doen.</Text>
          <Field label="Naam" accessibilityLabel="Naam" value={name} onChangeText={setName} autoComplete="name" editable={!locked} maxLength={255} />
          <Field label="E-mailadres" accessibilityLabel="E-mailadres" value={email} onChangeText={setEmail} autoComplete="email" autoCapitalize="none" autoCorrect={false} keyboardType="email-address" editable={!locked} maxLength={255} />
          <Field label="Wachtwoord" accessibilityLabel="Wachtwoord" value={password} onChangeText={setPassword} autoComplete="new-password" secureTextEntry editable={!locked} maxLength={128} />
          <Text className="mb-4 text-[13px] text-ink-50">Gebruik minimaal 8 tekens.</Text>
          <Field label="Herhaal wachtwoord" accessibilityLabel="Herhaal wachtwoord" value={confirmation} onChangeText={setConfirmation} autoComplete="new-password" secureTextEntry editable={!locked} maxLength={128} onSubmitEditing={() => void submit()} />
        </>}
        {!!(error || waitingError) && <Text accessibilityRole="alert" className="mb-4 text-[14px] text-red-700">{error || waitingError}</Text>}
        <View className="mt-3 gap-3">
          {challenge ? <>
            <Button title={busy ? 'Even geduld…' : 'Bevestigingsmail opnieuw versturen'} variant="text" disabled={locked} onPress={() => void submit()} />
            <Button title="E-mailadres wijzigen" variant="text" disabled={locked} onPress={() => { if (submitting.current) return; waitingController.current?.abort(); setChallenge(null); setExpired(false); setError(''); setWaitingError(''); }} />
          </> : <Button title={busy ? 'Account aanmaken…' : 'Account aanmaken'} onPress={() => void submit()} disabled={locked} trailing={busy ? <ActivityIndicator color="#fff" /> : undefined} />}
          <Button title="Ik heb al een account" variant="text" disabled={locked} onPress={() => { if (submitting.current) return; waitingController.current?.abort(); router.replace('/onboarding/welcome'); }} />
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

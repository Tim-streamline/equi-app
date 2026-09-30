import { KeyboardScrollView as ScrollView, KeyboardViewport as KeyboardAvoidingView } from '@/components/ui/KeyboardForm';
import { useRef, useState } from 'react';
import { ActivityIndicator, Platform, View, Text } from 'react-native';
import { Redirect, router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { SubHeader } from '@/components/ui/SubHeader';
import { Field } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { useStoreMutations } from '@/db/hooks';
import { useDb } from '@/db/provider';

export default function AddHorseScreen() {
  const { isLoggedIn, currentUserId, selectHorse } = useDb();
  const { upsertHorse } = useStoreMutations();
  const [name, setName] = useState('');
  const [breed, setBreed] = useState('');
  const [age, setAge] = useState('');
  const [sex, setSex] = useState('');
  const [weight, setWeight] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const saving = useRef(false);
  const finish = () => { if (!saving.current) router.replace('/(tabs)/(pager)/home'); };
  async function save() {
    if (saving.current || !currentUserId) return;
    setError('');
    if (!name.trim()) { setError('Vul de naam van je paard in.'); return; }
    if (age.trim() && (!/^\d+$/.test(age.trim()) || Number(age) > 60)) { setError('Vul een leeftijd van 0 tot en met 60 jaar in.'); return; }
    if (weight.trim() && (!/^\d+$/.test(weight.trim()) || Number(weight) < 1 || Number(weight) > 2000)) { setError('Vul een gewicht van 1 tot en met 2000 kg in.'); return; }
    saving.current = true; setBusy(true);
    try {
      // An empty ID means create a new horse, never edit the selected horse.
      const id = await upsertHorse('', {
        ownerId: currentUserId, name: name.trim(), breed: breed.trim() || null,
        age: age.trim() ? Number(age) : null, sex: sex || null,
        weightKg: weight.trim() ? Number(weight) : null,
        status: 'active', createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
      });
      selectHorse(id);
      router.replace('/(tabs)/(pager)/home');
    } catch {
      setError('Je paard kon niet worden opgeslagen. Probeer opnieuw.');
    } finally { saving.current = false; setBusy(false); }
  }
  if (!isLoggedIn) return <Redirect href="/onboarding/welcome" />;
  return <SafeAreaView className="flex-1 bg-canvas">
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <SubHeader title="Paard toevoegen" onBack={finish} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 40 }}>
        <Text className="mb-2 font-bold text-[26px] text-ink">Vertel me over je paard.</Text>
        <Text className="mb-6 text-[14px] text-ink-50">Alleen de naam is verplicht. Je kunt deze stap overslaan en later een paard toevoegen via Mijn paarden.</Text>
        <Field label="Naam van je paard" accessibilityLabel="Naam van je paard" value={name} onChangeText={setName} editable={!busy} maxLength={255} />
        <Field label="Ras (optioneel)" accessibilityLabel="Ras" value={breed} onChangeText={setBreed} editable={!busy} maxLength={255} />
        <View className="flex-row gap-3">
          <View className="flex-1"><Field label="Leeftijd in jaren" accessibilityLabel="Leeftijd in jaren" value={age} onChangeText={setAge} keyboardType="number-pad" editable={!busy} maxLength={2} /></View>
          <View className="flex-1"><Field label="Gewicht in kg" accessibilityLabel="Gewicht in kg" value={weight} onChangeText={setWeight} keyboardType="number-pad" editable={!busy} maxLength={4} /></View>
        </View>
        <Text className="mb-2 font-semi text-[12px] text-ink-70">Geslacht (optioneel)</Text>
        <View className="mb-4 flex-row gap-2">
          {['merrie', 'ruin', 'hengst'].map(option => <View className="flex-1" key={option}><Button title={option} variant={sex === option ? 'deep' : 'ghost'} disabled={busy} className="px-2" onPress={() => setSex(sex === option ? '' : option)} /></View>)}
        </View>
        {!!error && <Text accessibilityRole="alert" className="mb-4 text-[14px] text-red-700">{error}</Text>}
        <View className="mt-3 gap-3">
          <Button title={busy ? 'Paard opslaan…' : 'Paard toevoegen'} onPress={() => void save()} disabled={busy} trailing={busy ? <ActivityIndicator color="#fff" /> : undefined} />
          <Button title="Nu overslaan" variant="ghost" onPress={finish} disabled={busy} />
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

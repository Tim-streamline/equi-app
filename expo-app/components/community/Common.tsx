import type { ReactNode } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, Text, View, KeyboardAvoidingView, Platform } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';

export function Action({ label, onPress, disabled = false, primary = false }: { label: string; onPress: () => void; disabled?: boolean; primary?: boolean }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={label} disabled={disabled} onPress={onPress}
    className={`rounded-full px-4 py-2.5 ${primary ? 'bg-teal-700' : 'bg-mint-50'} ${disabled ? 'opacity-40' : ''}`}>
    <Text className={`font-semi text-[14px] ${primary ? 'text-white' : 'text-mint-700'}`}>{label}</Text>
  </Pressable>;
}
export function CommunityDialog({ open, close, title, children }: { open: boolean; close: () => void; title: string; children: ReactNode }) {
  return <Modal visible={open} onRequestClose={close} animationType="slide" presentationStyle="pageSheet">
    <SafeAreaView className="flex-1 bg-canvas">
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View className="flex-row items-center justify-between px-5 py-4">
          <Text className="flex-1 font-bold text-[22px] text-ink">{title}</Text><Action label="Sluiten" onPress={close} />
        </View>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 20, gap: 16 }}>{children}</ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  </Modal>;
}
export function MembershipNotice() {
  return <View className="mx-5 mb-4 rounded-2xl bg-mint-50 p-4">
    <Text className="font-semi text-[15px] text-teal-700">Lees mee, stel vragen met Basic of Plus</Text>
    <Text className="mt-1 mb-3 text-[14px] leading-5 text-ink-70">Lezen en bewaren is gratis. Met Basic of Plus kun je berichten plaatsen, antwoorden en liken.</Text>
    <View className="self-start"><Action label="Bekijk abonnementen" onPress={() => router.push('/(tabs)/account/subscription')} /></View>
  </View>;
}
export function ResourceState({ error, retry }: { error: string | null; retry: () => void }) {
  return <View className="items-center gap-4 p-8">{error ? <><Text className="text-center text-ink-70">{error}</Text><Action label="Opnieuw proberen" onPress={retry} /></> : <ActivityIndicator color="#127A79" />}</View>;
}
export function Pagination({ page, last, change }: { page: number; last: number; change: (page: number) => void }) {
  if (last <= 1) return null;
  return <View className="flex-row items-center justify-between gap-2 px-5 py-5">
    <Action label="Vorige" disabled={page <= 1} onPress={() => change(page - 1)} />
    <Text className="text-ink-50">{page} / {last}</Text>
    <Action label="Volgende" disabled={page >= last} onPress={() => change(page + 1)} />
  </View>;
}

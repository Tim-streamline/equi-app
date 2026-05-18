import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Check, ArrowRight } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';

const BENEFITS = [
  'Onbeperkte scans + AI-advies',
  'Toegang tot alle bibliotheek-content',
  'Direct vragen stellen aan Shelley',
  'Tot 3 paarden',
];

const PAYMENTS = [
  { d: '22 apr 2026', v: '€ 12,00' },
  { d: '22 mrt 2026', v: '€ 12,00' },
  { d: '22 feb 2026', v: '€ 12,00' },
];

export default function SubscriptionScreen() {
  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader title="Abonnement" onBack={() => router.back()} />
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 120 }}>
          <View className="mb-3.5 rounded-card bg-teal-700 p-[18px]">
            <View className="flex-row justify-between">
              <View className="self-start rounded-pill bg-mint-500 px-3 py-1">
                <Text className="font-semi text-white text-[12px]">Plus · Actief</Text>
              </View>
              <Text className="text-white/70 text-[12px]">Sinds april 2026</Text>
            </View>
            <View className="mt-3 flex-row items-baseline">
              <Text className="font-bold text-white" style={{ fontSize: 32 }}>€ 12</Text>
              <Text className="font-medium text-white/70 ml-1.5" style={{ fontSize: 14 }}>/ maand</Text>
            </View>
            <Text className="text-white/75 text-[13px] mt-1">Verlengt automatisch op 22 mei</Text>

            <View className="mt-4 pt-4 gap-2" style={{ borderTopWidth: 1, borderTopColor: 'rgba(255,255,255,0.12)' }}>
              {BENEFITS.map((b) => (
                <View key={b} className="flex-row items-center gap-2.5">
                  <Check size={16} color="#5FD7CB" strokeWidth={2.5} />
                  <Text className="text-white text-[13px]">{b}</Text>
                </View>
              ))}
            </View>
          </View>

          <SectionTitle>Upgrade pad</SectionTitle>
          <View className="rounded-card border border-mint-300 bg-white p-[18px] mb-4">
            <View className="flex-row items-start justify-between">
              <View className="flex-1">
                <View className="self-start"><Chip label="Aanbevolen" /></View>
                <Text className="font-bold text-ink mt-2" style={{ fontSize: 18 }}>Opleiding bundel</Text>
                <Text className="text-[12px] text-ink-50 mt-0.5">EquiNova Plus + 8-maands opleiding</Text>
              </View>
              <View className="items-end">
                <Text className="font-bold text-ink text-[15px]">€ 4.997</Text>
                <Text className="text-[11px] text-ink-50">eenmalig</Text>
              </View>
            </View>
            <View className="mt-3">
              <Button title="Bekijk opleiding" trailing={<ArrowRight size={18} color="#fff" />} />
            </View>
          </View>

          <SectionTitle>Betalingen</SectionTitle>
          <View className="px-1">
            {PAYMENTS.map((p) => (
              <View key={p.d} className="flex-row justify-between border-b border-ink-8 py-3">
                <Text className="text-ink text-[14px]">{p.d}</Text>
                <Text className="font-semi text-ink text-[14px]">{p.v}</Text>
              </View>
            ))}
          </View>

          <View className="pt-5">
            <Button title="Abonnement opzeggen" variant="ghost" textClassName="text-danger" />
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

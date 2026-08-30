import { ActivityIndicator, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Check } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { useHorseDashboard } from '@/hooks/useHorseDashboard';

export default function PlusScreen() {
  const { data, error, loading } = useHorseDashboard();
  const offer = data?.plusOffer;
  return (
    <SafeAreaView className="flex-1 bg-canvas">
      <SubHeader title="Ontdek Plus" onBack={() => router.back()} />
      <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: 40 }}>
        {loading && <ActivityIndicator color="#18BAB0" />}
        {!!error && <Text className="mb-4 text-[13px] text-ink-70">{error}</Text>}
        {offer ? (
          <View className="rounded-[22px] bg-[#127A79] p-6">
            <Text className="mb-3 text-[11px] uppercase tracking-[2px] text-white/75">Plus</Text>
            <Text className="font-bold text-[25px] text-white">{offer.name}</Text>
            {!!offer.description && <Text className="mt-3 text-[15px] leading-[23px] text-white/90">{offer.description}</Text>}
            <Text className="mt-5 font-bold text-[28px] text-white">{offer.priceLabel}</Text>
            {!!offer.priceSuffix && <Text className="text-[14px] text-white/75">{offer.priceSuffix}</Text>}
            <View className="mt-6 gap-4">
              {offer.benefits.map((benefit, index) => (
                <View key={`${index}:${benefit}`} className="flex-row items-start gap-3">
                  <Check size={20} color="#8EE7DD" />
                  <Text className="flex-1 text-[15px] leading-[21px] text-white">{benefit}</Text>
                </View>
              ))}
            </View>
          </View>
        ) : !loading && !error ? <Text className="text-[15px] text-ink-70">De informatie over Plus is nog niet beschikbaar.</Text> : null}
      </ScrollView>
    </SafeAreaView>
  );
}

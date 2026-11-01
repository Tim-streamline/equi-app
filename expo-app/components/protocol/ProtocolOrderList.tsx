import { Alert, Linking, Pressable, Text, View } from 'react-native';
import type { DashboardOrderItem } from '@/lib/horse-dashboard';

export function ProtocolOrderList({ items, discount = '5% korting bij Dtails.nl met code: paardentherapeut-5' }: {
  items: DashboardOrderItem[];
  discount?: string;
}) {
  return <View className="gap-3">
    <View className="rounded-2xl bg-mint-50 p-4">
      <Text className="font-semi text-[15px] leading-[22px] text-teal-700">{discount}</Text>
    </View>
    {items.length === 0 && <Text className="py-5 text-[14px] text-ink-70">Er staan geen producten op deze bestellijst.</Text>}
    {items.map(item => {
      const option = item.purchaseOptions?.find(option => /^https?:\/\//i.test(option.url));
      return <View key={item.id} className="gap-2 rounded-2xl border border-ink-8 bg-white p-4">
        <Text className="font-semi text-[18px] text-ink">{item.name}</Text>
        <Text className="text-[16px] text-ink-70">Totaal nodig: <Text className="font-semi text-ink">{item.quantityLabel ?? 'hoeveelheid nog te bepalen'}</Text></Text>
        {option && <Pressable accessibilityRole="link" accessibilityLabel={`Bekijk / bestel ${item.name}`}
          onPress={() => void Linking.openURL(option.url).catch(() => Alert.alert('Productlink niet geopend', 'Probeer het opnieuw.'))} className="py-2">
          <Text className="font-semi text-[15px] text-teal-600">Bekijk / bestel product →</Text>
        </Pressable>}
      </View>;
    })}
  </View>;
}

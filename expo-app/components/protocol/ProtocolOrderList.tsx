import { Alert, Linking, Pressable, Text, View } from 'react-native';
import type { DashboardOrderItem } from '@/lib/horse-dashboard';

type PurchaseOption = DashboardOrderItem['purchaseOptions'][number];

const hostOf = (url: string) => url.replace(/^https?:\/\//i, '').split(/[/?#:]/)[0].replace(/^www\./i, '').toLowerCase();

/** All distinct http(s) links, each with a label that tells several shops apart. */
export function purchaseLinks(options: PurchaseOption[] | undefined) {
  const safe = (options ?? []).filter((option, index, all) => typeof option?.url === 'string'
    && /^https?:\/\/[^\s/?#]+/i.test(option.url) && all.findIndex(other => other?.url === option.url) === index);
  return safe.map((option, index) => {
    if (safe.length === 1) return { url: option.url, suffix: '' };
    const host = hostOf(option.url);
    const sameHost = safe.filter(other => hostOf(other.url) === host).length > 1;
    return { url: option.url, suffix: sameHost ? ` bij ${host} (optie ${index + 1})` : ` bij ${host}` };
  });
}

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
      const links = purchaseLinks(item.purchaseOptions);
      return <View key={item.id} className="gap-2 rounded-2xl border border-ink-8 bg-white p-4">
        <Text className="font-semi text-[18px] text-ink">{item.name}</Text>
        <Text className="text-[16px] text-ink-70">Totaal nodig: <Text className="font-semi text-ink">{item.quantityLabel ?? 'hoeveelheid nog te bepalen'}</Text></Text>
        {links.map(link => <Pressable key={link.url} accessibilityRole="link" accessibilityLabel={`Bekijk / bestel ${item.name}${link.suffix}`}
          onPress={() => void Linking.openURL(link.url).catch(() => Alert.alert('Productlink niet geopend', 'Probeer het opnieuw.'))} className="py-2">
          <Text className="font-semi text-[15px] text-teal-600">{`Bekijk / bestel product${link.suffix} →`}</Text>
        </Pressable>)}
      </View>;
    })}
  </View>;
}

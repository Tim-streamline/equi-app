import { Alert, Linking, Pressable, Text, View } from 'react-native';
import type { DashboardOrderItem } from '@/lib/horse-dashboard';
import { splitDiscount } from '@/lib/protocol-order-scope';
import { PLAIN_CARD } from '@/components/ui/styles';
import { SwitchRow } from '@/components/ui/SwitchRow';
import { hasExtraGifts } from '@/lib/protocol-order-scope';
import { PROTOCOL_SCALE as S, PROTOCOL_TEXT as T } from './styles';
import { DoneCircle } from './DoneCircle';

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

/** One key per product and phase, so ticking a phase's list does not tick the product for later phases. */
export function orderKeys(item: DashboardOrderItem): string[] {
  const phases = Array.isArray(item.components) ? Array.from(new Set(item.components.map(component => component.phaseId))) : [];
  return phases.length ? phases.map(phaseId => `${item.productKey}:${phaseId}`) : [`${item.productKey}:all`];
}

export function ProtocolOrderList({ items, discount = '5% korting bij Dtails.nl met code: paardentherapeut-5', orderedKeys, onToggle, twiceDaily = true, onTwiceDailyChange }: {
  items: DashboardOrderItem[];
  discount?: string;
  /** Products the customer ticked off as ordered; without onToggle the list has no checkboxes. */
  orderedKeys?: string[];
  onToggle?: (keys: string[], ordered: boolean) => void;
  /** Whether the customer can give a second gift a day; shown only when a product in the list has one. */
  twiceDaily?: boolean;
  onTwiceDailyChange?: (value: boolean) => void;
}) {
  const ordered = new Set(orderedKeys ?? []);
  // The code gets a line of its own so it never breaks at its hyphen. A non-breaking hyphen would
  // also stop the break, but customers copy this code into a shop, which would then reject it.
  const { label, code } = splitDiscount(discount);
  return <View className="gap-3">
    <View className="rounded-[22px] bg-mint-50 p-4">
      <Text className={`${S.rowTitle} text-teal-700`}>{label}</Text>
      {code ? <Text selectable className={`${S.rowTitle} font-bold text-teal-700`}>{code}</Text> : null}
    </View>
    {onTwiceDailyChange && hasExtraGifts(items) && <View className={PLAIN_CARD}>
      <SwitchRow label="Ben je in de gelegenheid om 2x daags te geven?"
        description="Voor sommige kruiden is een tweede gift per dag wenselijk. Kun je maar 1x per dag geven? Zet dit schuifje dan uit, dan passen we de hoeveelheden daarop aan."
        value={twiceDaily} onChange={onTwiceDailyChange} />
    </View>}
    {items.length === 0 && <Text className={`py-5 ${T.body}`}>Er staan geen producten op deze bestellijst.</Text>}
    {items.map(item => {
      const links = purchaseLinks(item.purchaseOptions);
      const keys = orderKeys(item);
      const done = keys.every(key => ordered.has(key));
      return <View key={item.id} className={`gap-2 ${PLAIN_CARD}`}>
        {onToggle ? <Pressable accessibilityRole="checkbox" accessibilityLabel={`${item.name} besteld`} accessibilityState={{ checked: done }}
          onPress={() => onToggle(keys, !done)} className="min-h-[44px] flex-row items-center gap-3">
          <DoneCircle done={done} />
          <Text className={`flex-1 ${T.cardTitle} ${done ? 'text-ink-70 line-through' : ''}`}>{item.name}</Text>
        </Pressable> : <Text className={T.cardTitle}>{item.name}</Text>}
        <Text className={T.row}>Totaal nodig: <Text className="font-semi text-ink">{item.quantityLabel ?? 'hoeveelheid nog te bepalen'}</Text></Text>
        {links.map(link => <Pressable key={link.url} accessibilityRole="link" accessibilityLabel={`Bekijk / bestel ${item.name}${link.suffix}`}
          onPress={() => void Linking.openURL(link.url).catch(() => Alert.alert('Productlink niet geopend', 'Probeer het opnieuw.'))} className="min-h-[44px] justify-center">
          <Text className={T.link}>{`Bekijk / bestel product${link.suffix} →`}</Text>
        </Pressable>)}
      </View>;
    })}
  </View>;
}

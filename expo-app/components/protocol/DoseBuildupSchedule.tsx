import { Text, View } from 'react-native';
import type { DashboardSupplement } from '@/lib/horse-dashboard';
import { buildupSchedule, buildupState } from '@/lib/protocol-buildup';
import { PROTOCOL_SCALE as S, PROTOCOL_TEXT as T } from './styles';

export function DoseBuildupSchedule({ item, date }: { item: DashboardSupplement; date?: string }) {
  const rows = buildupSchedule(item, date);
  const current = date ? buildupState(item, date) : null;
  if (!rows.length) return item.buildupLabel ? <Text className={`mb-4 ${T.secondary}`}>{item.buildupLabel}</Text> : null;
  return <View className="mb-5 gap-3 rounded-[22px] bg-mint-50 p-4">
    <View className="gap-1">
      <Text accessibilityRole="header" className={`${S.section} text-teal-700`}>Zo bouw je de dosering op</Text>
      <Text className={T.meta}>Per gift · alleen geregistreerde toedieningsdagen tellen mee.</Text>
    </View>
    {!!current && <Text className="font-semi text-[14px] text-teal-700">Vandaag: {current.currentDosage} per gift</Text>}
    <View>{rows.map(row => <View key={row.from} className={`flex-row items-center justify-between gap-3 rounded-xl px-3 py-2.5 ${row.current ? 'bg-white' : ''}`}>
      <View className="flex-1 gap-0.5"><Text className={`text-[14px] ${row.current ? 'font-semi text-teal-700' : 'font-sans text-ink-70'}`}>{row.from === row.to ? `Dag ${row.from}` : `Dag ${row.from}–${row.to}`}</Text>{row.current && <Text className="font-semi text-[12px] text-teal-600">Huidige stap</Text>}</View>
      <Text className={`text-[15px] ${row.current ? 'font-semi text-teal-700' : 'font-sans text-ink'}`}>{row.dosage}</Text>
    </View>)}</View>
    <Text className={T.meta}>Daarna blijf je op {item.buildup?.fullDosage} per gift. Op dagen zonder registratie gaat de opbouw niet verder.</Text>
  </View>;
}

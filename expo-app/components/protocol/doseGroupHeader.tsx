import { Text, View } from 'react-native';
import { EYEBROW } from '@/components/ui/styles';
import type { todayGroups } from '@/lib/protocol-today';
import { PROTOCOL_TEXT as T } from './styles';

type DoseGroup = ReturnType<typeof todayGroups>[number];

/**
 * The heading above a group of doses, shared by Today and the day sheet. The daily gift needs none;
 * a further gift (more "Giften per dag" in the admin) is preferred, not required. `hint` explains it.
 * A plain render function, not a component, so the screens render its markup directly.
 */
export function doseGroupHeader(group: DoseGroup, hint?: string) {
  if (group.number === null) return <Text accessibilityRole="header" className={`${EYEBROW} text-ink-70`}>{group.label}</Text>;
  if (group.number <= 1) return null;
  const row = <View className={`flex-row items-center justify-between ${hint ? 'gap-3' : 'mb-1'}`}>
    <Text accessibilityRole="header" className={`${EYEBROW} text-ink-70`}>{group.label}<Text className="font-sans normal-case tracking-normal text-[12px] text-ink-50"> · bij voorkeur</Text></Text>
    <Text className={T.meta}>{group.done} van {group.items.length}</Text>
  </View>;
  return hint ? <View className="mb-1 gap-0.5">{row}<Text className={T.secondary}>{hint}</Text></View> : row;
}

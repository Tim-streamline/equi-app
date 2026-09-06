import { Pressable, Text, View } from 'react-native';
import { PROTOCOL_TABS, type ProtocolTab } from '@/lib/protocol-tabs';

export function ProtocolTabs({ selected, onSelect }: {
  selected: ProtocolTab;
  onSelect: (tab: ProtocolTab) => void;
}) {
  return (
    <View accessibilityRole="tablist" className="flex-row border-b border-ink-8 px-2">
      {PROTOCOL_TABS.map((tab) => (
        <Pressable
          key={tab.key}
          accessibilityRole="tab"
          accessibilityLabel={tab.label}
          accessibilityState={{ selected: selected === tab.key }}
          onPress={() => onSelect(tab.key)}
          className="min-h-[48px] min-w-0 flex-1 items-center justify-center border-b-2 px-0.5 py-2"
          style={{ borderBottomColor: selected === tab.key ? '#18BAB0' : 'transparent' }}
        >
          <Text
            numberOfLines={1}
            adjustsFontSizeToFit
            minimumFontScale={0.75}
            className={`w-full text-center font-semi text-[13px] ${selected === tab.key ? 'text-mint-700' : 'text-ink-70'}`}
          >
            {tab.label}
          </Text>
        </Pressable>
      ))}
    </View>
  );
}

import { Alert, Linking, Pressable, Text, View } from 'react-native';
import { Footprints, HeartPulse, Leaf, Microscope, TriangleAlert } from 'lucide-react-native';
import type { DashboardProtocol } from '@/lib/horse-dashboard';
import { careSections } from '@/lib/protocol-care';

const SECTION_ICONS = { environment: Leaf, movement: Footprints, care: HeartPulse, monitoring: Microscope };

export function CareAdvice({ protocol }: {
  protocol: Pick<DashboardProtocol, 'management' | 'movement'>;
}) {
  const sections = careSections(protocol);
  if (sections.length === 0) {
    return <Text className="text-[14px] leading-[22px] text-ink-70">Er zijn voor dit paard geen actieve zorgadviezen.</Text>;
  }

  return (
    <View className="gap-8">
      {sections.map((section) => {
        const Icon = SECTION_ICONS[section.id];
        return (
          <View key={section.id}>
            <View className="mb-3 flex-row items-center gap-2">
              <Icon size={16} color="#18BAB0" />
              <Text accessibilityRole="header" className="flex-1 font-semi text-[12px] uppercase tracking-[1px] text-ink-70">
                {section.title}
              </Text>
            </View>
            <View className="gap-3.5">
              {section.items.map((item) => (
                <View key={item.id} className="rounded-[22px] bg-white p-5">
                  <View className="flex-row items-start gap-2">
                    {item.action === 'avoid' && (
                      <TriangleAlert size={17} color="#B58339" accessibilityLabel="Vermijden of stoppen" />
                    )}
                    <Text className="flex-1 font-semi text-[16px] text-ink">{item.title}</Text>
                  </View>
                  {!!item.description && (
                    <Text className="mt-1 text-[14px] leading-[22px] text-ink-70">{item.description}</Text>
                  )}
                  {!!item.frequency && (
                    <Text className="mt-2 text-[13px] leading-[20px] text-ink-70">{item.frequency}</Text>
                  )}
                  {!!item.note && (
                    <Text className="mt-2 text-[13px] leading-[20px] text-ink-70">{item.note}</Text>
                  )}
                  {!!item.url && /^https?:\/\//i.test(item.url) && (
                    <Pressable
                      accessibilityRole="link"
                      onPress={() => void Linking.openURL(item.url!).catch(() => Alert.alert('Link niet beschikbaar'))}
                      className="min-h-[44px] justify-center pt-2"
                    >
                      <Text className="font-semi text-[13px] text-mint-700">{item.ctaLabel || 'Meer informatie'} →</Text>
                    </Pressable>
                  )}
                </View>
              ))}
            </View>
          </View>
        );
      })}
    </View>
  );
}

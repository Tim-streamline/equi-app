import { Alert, Linking, Pressable, Text, View } from 'react-native';
import { Footprints, HeartPulse, Leaf, Microscope, TriangleAlert } from 'lucide-react-native';
import type { DashboardProtocol } from '@/lib/horse-dashboard';
import { careSections } from '@/lib/protocol-care';
import { EYEBROW, PLAIN_CARD } from '@/components/ui/styles';
import { PROTOCOL_SCALE as S, PROTOCOL_TEXT as T } from './styles';

const SECTION_ICONS = { environment: Leaf, movement: Footprints, care: HeartPulse, monitoring: Microscope };

export function CareAdvice({ protocol }: {
  protocol: Pick<DashboardProtocol, 'management' | 'movement'>;
}) {
  const sections = careSections(protocol);
  if (sections.length === 0) {
    return <Text className={T.body}>Er zijn voor dit paard geen actieve zorgadviezen.</Text>;
  }

  return (
    <View className="gap-8">
      {sections.map((section) => {
        const Icon = SECTION_ICONS[section.id];
        return (
          <View key={section.id}>
            <View className="mb-3 flex-row items-center gap-2">
              <Icon size={16} color="#18BAB0" />
              <Text accessibilityRole="header" className={`flex-1 ${EYEBROW} text-ink-70`}>
                {section.title}
              </Text>
            </View>
            <View className="gap-3.5">
              {section.items.map((item) => (
                <View key={item.id} className={PLAIN_CARD}>
                  <View className="flex-row items-start gap-2">
                    {item.action === 'avoid' && (
                      <TriangleAlert size={17} color="#B58339" accessibilityLabel="Vermijden of stoppen" />
                    )}
                    <Text className={`flex-1 ${T.cardTitle}`}>{item.title}</Text>
                  </View>
                  {!!item.description && (
                    <View className="mt-3 gap-1">
                      <Text accessibilityRole="header" className={`${S.label} text-teal-700`}>Instructies</Text>
                      <Text className={T.body}>{item.description}</Text>
                    </View>
                  )}
                  {!!item.frequency && (
                    <Text className={`mt-3 ${T.secondary}`}>
                      <Text className="font-semi text-ink">Frequentie:</Text> {item.frequency}
                    </Text>
                  )}
                  {!!item.note && (
                    <View className="mt-5 gap-2 rounded-xl border-l-[3px] border-mint-500 bg-mint-50 p-4">
                      <Text accessibilityRole="header" className={`${S.label} text-teal-700`}>Persoonlijke toelichting van je therapeut</Text>
                      <Text className={`${S.body} text-ink`}>{item.note}</Text>
                    </View>
                  )}
                  {!!item.url && /^https?:\/\//i.test(item.url) && (
                    <Pressable
                      accessibilityRole="link"
                      onPress={() => void Linking.openURL(item.url!).catch(() => Alert.alert('Link niet beschikbaar'))}
                      className="min-h-[44px] justify-center pt-2"
                    >
                      <Text className={T.link}>{item.ctaLabel || 'Meer informatie'} →</Text>
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

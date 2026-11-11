import { Text, View } from "react-native";
import type { DashboardProtocol } from "@/lib/horse-dashboard";
import { EYEBROW, PLAIN_CARD } from "@/components/ui/styles";
import { PROTOCOL_TEXT as T } from "./styles";

export function ProtocolAnalysis({ analysis }: {
  analysis: DashboardProtocol["analysis"];
}) {
  // Older cached dashboards contain the long-form analysis, not the authored summary.
  if (!analysis?.summary || !Array.isArray(analysis.observations)) {
    return (
      <Text className={T.body}>
        De persoonlijke analyse is nog niet ingevuld voor dit paard.
      </Text>
    );
  }

  return (
    <View className="gap-6">
      <View className="rounded-[22px] bg-mint-50 p-5">
        <Text accessibilityRole="header" className={`${EYEBROW} text-mint-800`}>
          Persoonlijke analyse
        </Text>
        <View className="mt-3 gap-4" style={{ width: '100%', maxWidth: 720 }}>
          {analysis.summary.trim().split(/\r?\n\s*\r?\n/).map((paragraph, index) => (
            <Text key={index} selectable className="text-[16px] leading-[26px] text-ink">
              {paragraph}
            </Text>
          ))}
        </View>
      </View>

      {analysis.priorities.length > 0 && (
        <View>
          <Text accessibilityRole="header" className={`mb-3 ${EYEBROW} text-ink-70`}>
            Focus van het protocol
          </Text>
          <View className="gap-3">
            {analysis.priorities.slice(0, 4).map((point) => (
              <View key={point.id} className={PLAIN_CARD}>
                <Text className={T.section}>{point.title}</Text>
                <Text className={`mt-1 ${T.secondary}`}>{point.body}</Text>
              </View>
            ))}
          </View>
        </View>
      )}

      {analysis.observations.length > 0 && (
        <View>
          <Text accessibilityRole="header" className={`mb-3 ${EYEBROW} text-ink-70`}>
            Waar letten we op?
          </Text>
          <View className="gap-2">
            {analysis.observations.map((observation, index) => (
              <View key={index} className="flex-row items-start gap-2.5">
                <View className="mt-[9px] h-1.5 w-1.5 rounded-full bg-mint-500" />
                <Text className={`flex-1 ${T.body}`}>{observation}</Text>
              </View>
            ))}
          </View>
        </View>
      )}
      {!!analysis.expectationFollowUp?.trim() && (
        <View className="rounded-[22px] bg-mint-50 p-5">
          <Text accessibilityRole="header" className={`${EYEBROW} text-mint-800`}>
            Verwachting en vervolg
          </Text>
          <Text selectable className="mt-3 text-[16px] leading-[26px] text-ink">{analysis.expectationFollowUp}</Text>
        </View>
      )}
    </View>
  );
}

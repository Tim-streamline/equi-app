import { Text, View } from "react-native";
import type { DashboardProtocol } from "@/lib/horse-dashboard";

export function ProtocolAnalysis({ analysis }: {
  analysis: DashboardProtocol["analysis"];
}) {
  // Older cached dashboards contain the long-form analysis, not the authored summary.
  if (!analysis?.summary || !Array.isArray(analysis.observations)) {
    return (
      <Text className="text-[14px] leading-[22px] text-ink-70">
        De persoonlijke analyse is nog niet ingevuld voor dit paard.
      </Text>
    );
  }

  return (
    <View className="gap-6">
      <View className="rounded-[22px] bg-mint-50 p-4">
        <Text accessibilityRole="header" className="font-semi text-[12px] uppercase tracking-[0.8px] text-mint-800">
          Persoonlijke analyse
        </Text>
        <Text className="mt-2 text-[14px] leading-[21px] text-ink">
          {analysis.summary}
        </Text>
      </View>

      {analysis.priorities.length > 0 && (
        <View>
          <Text accessibilityRole="header" className="mb-3 font-semi text-[12px] uppercase tracking-[0.8px] text-ink-70">
            Focus van het protocol
          </Text>
          <View className="gap-3">
            {analysis.priorities.slice(0, 4).map((point) => (
              <View key={point.id} className="rounded-2xl bg-white px-4 py-3">
                <Text className="font-semi text-[15px] leading-[20px] text-ink">{point.title}</Text>
                <Text className="mt-1 text-[14px] leading-[20px] text-ink-70">{point.body}</Text>
              </View>
            ))}
          </View>
        </View>
      )}

      {analysis.observations.length > 0 && (
        <View>
          <Text accessibilityRole="header" className="mb-3 font-semi text-[12px] uppercase tracking-[0.8px] text-ink-70">
            Waar letten we op?
          </Text>
          <View className="gap-2">
            {analysis.observations.map((observation, index) => (
              <View key={index} className="flex-row items-start gap-2.5">
                <View className="mt-2 h-1.5 w-1.5 rounded-full bg-mint-500" />
                <Text className="flex-1 text-[14px] leading-[21px] text-ink-70">{observation}</Text>
              </View>
            ))}
          </View>
        </View>
      )}
    </View>
  );
}

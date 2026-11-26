import { Text, View } from "react-native";
import type { DashboardProtocol } from "@/lib/horse-dashboard";
import { EYEBROW } from "@/components/ui/styles";
import { PROTOCOL_TEXT as T } from "./styles";

/** Long texts read as an article on the page background: softer than full ink, with room between lines. */
const ARTICLE_TEXT = { color: "rgba(27, 42, 42, 0.82)" };

/** The therapist's own paragraphs: a blank line in the admin starts a new one. */
function article(title: string, text: string) {
  return (
    <View className="gap-3.5" style={{ width: "100%", maxWidth: 720 }}>
      <Text accessibilityRole="header" className={T.title}>{title}</Text>
      {text.trim().split(/\r?\n\s*\r?\n/).map((paragraph, index) => (
        <Text key={index} selectable className="text-[16px] leading-[27px]" style={ARTICLE_TEXT}>
          {paragraph}
        </Text>
      ))}
    </View>
  );
}

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
    <View className="gap-8">
      {article("Persoonlijke analyse", analysis.summary)}

      {analysis.observations.length > 0 && (
        <View className="gap-3">
          <Text accessibilityRole="header" className={`${EYEBROW} text-ink-70`}>
            Waar letten we op?
          </Text>
          <View className="rounded-[22px] bg-white px-5 py-1">
            {analysis.observations.map((observation, index) => (
              <View key={index} className={`flex-row items-start gap-3 py-3.5 ${index < analysis.observations.length - 1 ? "border-b border-ink-8" : ""}`}>
                <View className="mt-[9px] h-1.5 w-1.5 rounded-full bg-mint-500" />
                <Text className={`flex-1 ${T.body}`}>{observation}</Text>
              </View>
            ))}
          </View>
        </View>
      )}

      {!!analysis.expectationFollowUp?.trim() && (
        <View className="gap-8">
          <View className="h-px bg-ink-8" />
          {article("Verwachting en vervolg", analysis.expectationFollowUp)}
        </View>
      )}
    </View>
  );
}

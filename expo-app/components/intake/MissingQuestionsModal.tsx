import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import { ChevronRight, X } from 'lucide-react-native';

import { Field, Section } from '@/lib/intake/schema';
import { Button } from '@/components/ui/Button';

export type MissingQuestionSection = { section: Section; fields: Field[] };

export function MissingQuestionsModal({ visible, sections, onClose, onSelect }: {
  visible: boolean;
  sections: MissingQuestionSection[];
  onClose: () => void;
  onSelect: (section: Section, field: Field) => void;
}) {
  const total = sections.reduce((count, item) => count + item.fields.length, 0);
  const orderedSections = [...sections].sort((a, b) => a.section.nr - b.section.nr);

  return <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
    <View className="flex-1 items-center justify-center bg-black/50 px-4 py-8">
      <View accessibilityViewIsModal className="w-full overflow-hidden rounded-[24px] bg-canvas" style={{ maxWidth: 560, maxHeight: '88%' }}>
        <View className="flex-row items-start gap-3 border-b border-ink-8 bg-white px-5 pb-4 pt-5">
          <View className="flex-1">
            <Text className="text-[21px] font-bold leading-[28px] text-ink">Nog vragen open</Text>
            <Text className="mt-1 text-[13px] leading-[19px] text-ink-70">
              {total} verplichte {total === 1 ? 'vraag' : 'vragen'} in {sections.length} {sections.length === 1 ? 'sectie' : 'secties'}. Tik op een vraag om erheen te gaan.
            </Text>
          </View>
          <Pressable accessibilityRole="button" accessibilityLabel="Sluiten" onPress={onClose} className="rounded-full p-2">
            <X size={20} color="#1B2A2A" />
          </Pressable>
        </View>
        <ScrollView className="min-h-0" contentContainerStyle={{ padding: 20, gap: 16 }}>
          {orderedSections.map(({ section, fields }) => <View key={section.id}>
            <Text className="mb-2 text-[12px] font-bold uppercase tracking-wide text-mint-700">
              Sectie {section.nr + 1} · {section.title} · {fields.length}
            </Text>
            <View className="overflow-hidden rounded-2xl border border-ink-8 bg-white">
              {fields.map((field, index) => <Pressable key={field.id} accessibilityRole="button"
                accessibilityLabel={`Ga naar vraag: ${field.label} in ${section.title}`}
                onPress={() => onSelect(section, field)}
                className={`flex-row items-center gap-3 px-4 py-3 ${index > 0 ? 'border-t border-ink-8' : ''}`}>
                <Text className="flex-1 text-[14px] leading-[20px] text-ink">{field.label}</Text>
                <ChevronRight size={17} color="#128C85" />
              </Pressable>)}
            </View>
          </View>)}
        </ScrollView>
        <View className="border-t border-ink-8 bg-white px-5 py-4">
          <Button title="Sluiten" variant="ghost" onPress={onClose} />
        </View>
      </View>
    </View>
  </Modal>;
}

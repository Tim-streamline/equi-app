import { useEffect, useState } from "react";
import { View, Text, ScrollView, Pressable, Modal } from "react-native";
import { router } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import { ChevronDown } from "lucide-react-native";
import { useHorse, useHorsesByOwner } from "@/db/hooks";
import { useDb } from "@/db/provider";

/**
 * Slim pill with the selected horse for the header bar; it opens a picker to
 * switch horses. Renders nothing without a horse. The touch target stays 44pt.
 * `openSignal` opens the picker whenever it changes to a truthy value (deep link).
 */
export function HorseSwitcher({ openSignal }: { openSignal?: string }) {
  const horse = useHorse();
  const horses = useHorsesByOwner().filter((item) => item.status === "active");
  const { selectHorse } = useDb();
  const [pickerOpen, setPickerOpen] = useState(false);
  useEffect(() => { if (openSignal) setPickerOpen(true); }, [openSignal]);
  if (!horse.id) return null;
  return (
    <>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`Wissel paard, nu ${horse.name ?? "geen paard"}`}
        onPress={() => setPickerOpen(true)}
        className="h-11 justify-center"
      >
        <View className="flex-row items-center gap-1.5 rounded-pill border border-ink-8 bg-white py-1 pl-1 pr-2.5">
          <View className="h-6 w-6 items-center justify-center rounded-full bg-mint-500">
            <Text className="font-semi text-[11px] text-teal-900" maxFontSizeMultiplier={1.2}>
              {String(horse.name ?? "").charAt(0)}
            </Text>
          </View>
          <Text numberOfLines={1} maxFontSizeMultiplier={1.3} className="max-w-[120px] font-semi text-[14px] leading-[18px] text-ink">
            {horse.name ?? "Kies paard"}
          </Text>
          <ChevronDown size={14} color="#536C6B" />
        </View>
      </Pressable>
      <Modal
        visible={pickerOpen}
        transparent
        animationType="slide"
        onRequestClose={() => setPickerOpen(false)}
      >
        <Pressable
          onPress={() => setPickerOpen(false)}
          className="flex-1 justify-end bg-black/30 active:opacity-100"
        >
          <Pressable
            onPress={(e) => e.stopPropagation()}
            className="max-h-[75%] rounded-t-[28px] bg-canvas p-5 active:opacity-100"
          >
            <Text className="mb-4 font-bold text-[20px] text-ink">
              Kies je paard
            </Text>
            <ScrollView>
              {horses.map((item) => (
                <Pressable
                  key={item.id}
                  accessibilityRole="button"
                  accessibilityState={{ selected: item.id === horse.id }}
                  onPress={() => {
                    selectHorse(item.id);
                    setPickerOpen(false);
                  }}
                  className="mb-2 rounded-2xl bg-white p-4"
                >
                  <Text className="font-semi text-[16px] text-ink">
                    {item.name}
                  </Text>
                </Pressable>
              ))}
            </ScrollView>
            <SafeAreaView edges={["bottom"]}>
              <Pressable accessibilityRole="button" accessibilityLabel="Paard toevoegen vanuit paardenlijst"
                onPress={() => { setPickerOpen(false); router.push({ pathname: '/onboarding/add-horse', params: { returnTo: 'horse-picker' } }); }}
                className="mt-3 rounded-full border border-mint-500 p-3">
                <Text className="text-center font-semi text-teal-600">+ Paard toevoegen</Text>
              </Pressable>
              <Pressable
                onPress={() => setPickerOpen(false)}
                className="mt-2 rounded-full bg-mint-500 p-3"
              >
                <Text className="text-center font-bold text-teal-900">
                  Sluiten
                </Text>
              </Pressable>
            </SafeAreaView>
          </Pressable>
        </Pressable>
      </Modal>
    </>
  );
}

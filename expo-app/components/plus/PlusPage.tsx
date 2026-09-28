import { useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View, useWindowDimensions } from 'react-native';
import { Image } from 'expo-image';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Check, Minus, Plus, X } from 'lucide-react-native';
import { PlusPageData } from '@/lib/plus-page';
import { Button } from '@/components/ui/Button';

export function PlusPage({ data, apiBaseUrl, onIntake }: { data: PlusPageData; apiBaseUrl: string; onIntake: () => void }) {
  const c = data.content;
  const [sheet, setSheet] = useState<'confirm' | 'welcome' | null>(null);
  const [faq, setFaq] = useState<number | null>(0);
  const { width, height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const open = () => setSheet('confirm');
  const close = () => setSheet(null);
  const reviewWidth = Math.min(width - 66, 380);
  const price = <View className="flex-row flex-wrap items-baseline gap-x-2"><Text className="font-bold text-[30px] text-white">{c.price}</Text><Text className="text-[15px] text-white/80">{c.period}</Text></View>;
  return <>
    <ScrollView testID="plus-page" contentContainerStyle={{ width: '100%', maxWidth: 560, alignSelf: 'center', paddingBottom: 32 }}>
      <View className="mx-4 mt-2 overflow-hidden rounded-[24px] bg-[#0B4A49]">
        <Image source={{ uri: apiBaseUrl + data.heroImageUrl }} accessibilityLabel="Een vrouw met haar paard" contentFit="cover" contentPosition={{ top: '40%', left: '50%' }} transition={180} style={{ width: '100%', height: 140 }} />
        <View className="gap-3 p-[18px]">
          <Text className="font-semi text-[10px] tracking-[2px] text-mint-200">EQUI · APP · PLUS</Text>
          <Text accessibilityRole="header" className="font-bold text-[27px] leading-[29px] text-white">{c.heroTitle}</Text>
          <Text className="text-[15px] leading-[21px] text-white/90">{c.heroBody}</Text>
          {price}
          <Button title={c.heroButton} className="rounded-full py-3" onPress={open} />
        </View>
      </View>

      <View className="gap-5 px-5 pb-8 pt-9">
        <View className="gap-1"><Text className="text-[10px] tracking-[2px] text-teal-500">HOE HET WERKT</Text><Text accessibilityRole="header" className="font-bold text-[23px] text-teal-500">{c.stepsTitle}</Text></View>
        {c.steps.map((step, index) => <View key={index} className="flex-row items-start gap-3">
          <View className="h-9 w-9 items-center justify-center rounded-full bg-teal-500"><Text className="font-bold text-[16px] text-white">{index + 1}</Text></View>
          <View className="flex-1 gap-1"><Text className="font-bold text-[16px] leading-[21px] text-ink">{step.title}</Text><Text className="text-[15px] leading-[22px] text-ink">{step.body}</Text></View>
        </View>)}
        <Button title={c.stepsButton} variant="deep" className="mt-2 rounded-full px-3 py-3" textClassName="text-center" onPress={open} />
      </View>

      <View className="gap-5 px-5 pb-9">
        <View className="flex-row items-center gap-3">
          {data.portraitImageUrl ? <Image source={{ uri: apiBaseUrl + data.portraitImageUrl }} accessibilityLabel="Shelley" contentFit="cover" style={{ width: 76, height: 76, borderRadius: 38 }} /> : <View accessibilityLabel="Shelley" className="h-[76px] w-[76px] items-center justify-center rounded-full bg-mint-100"><Text className="font-semi text-[28px] text-teal-700">S</Text></View>}
          <View className="flex-1 gap-1"><Text className="text-[10px] tracking-[2px] text-teal-500">JOUW BEGELEIDER</Text><Text accessibilityRole="header" className="font-bold text-[23px] text-teal-500">{c.guideTitle}</Text></View>
        </View>
        <Text className="text-[16px] leading-[24px] text-ink">{c.guideBody}</Text>
        <Text className="font-semi-italic text-[15px] text-teal-500">{c.guideSignature}</Text>
      </View>

      {c.reviews.length > 0 && <View className="gap-3 pb-9">
        <Text accessibilityRole="header" className="px-5 font-bold text-[23px] text-teal-500">{c.reviewsTitle}</Text>
        {c.reviewsAreExamples && <Text className="px-5 text-[12px] text-ink-50">Voorbeeldreviews</Text>}
        <ScrollView horizontal showsHorizontalScrollIndicator={false} snapToInterval={reviewWidth + 12} decelerationRate="fast" contentContainerStyle={{ paddingHorizontal: 20, gap: 12 }} accessibilityLabel="Reviews van eigenaren">
          {c.reviews.map((review, index) => <View key={index} style={{ width: reviewWidth }} className="gap-4 rounded-[20px] border border-ink-8 bg-white p-5">
            <Text className="font-bold text-[17px] leading-[22px] text-ink">{review.title}</Text><Text className="flex-1 text-[15px] leading-[22px] text-ink">“{review.quote}”</Text>
            <View className="flex-row items-center gap-2"><View className="h-8 w-8 items-center justify-center rounded-full bg-mint-100"><Text className="font-bold text-teal-700">{review.name.charAt(0)}</Text></View><Text className="flex-1 text-[12px] text-ink-70">{review.name}</Text></View>
          </View>)}
        </ScrollView>
      </View>}

      <View className="mx-4 gap-4 rounded-[24px] bg-[#0B4A49] p-5">
        <Text className="text-[10px] tracking-[2px] text-mint-200">PLUS</Text>{price}
        <View className="gap-3">{c.benefits.map((benefit, index) => <View key={index} className="flex-row items-start gap-3"><Check size={19} color="#5FD7CB" /><Text className="flex-1 text-[15px] leading-[21px] text-white">{benefit}</Text></View>)}</View>
        <Button title={c.priceButton} className="rounded-full bg-white py-3 active:bg-mint-100" textClassName="text-teal-700" onPress={open} />
        <Text className="text-center text-[12px] text-white/75">{c.priceNote}</Text>
      </View>

      <View className="px-5 pt-9">
        <Text accessibilityRole="header" className="mb-3 font-bold text-[23px] text-teal-500">Veelgestelde vragen</Text>
        {c.faqs.map((item, index) => <View key={index} className="border-b border-ink-15 py-4">
          <Pressable accessibilityRole="button" accessibilityState={{ expanded: faq === index }} onPress={() => setFaq(faq === index ? null : index)} className="min-h-11 flex-row items-center gap-4">
            <Text className="flex-1 font-semi text-[16px] leading-[21px] text-ink">{item.question}</Text>{faq === index ? <Minus size={18} color="#127A79" /> : <Plus size={18} color="#127A79" />}
          </Pressable>
          {faq === index && <Text className="pt-3 text-[15px] leading-[23px] text-ink-70">{item.answer}</Text>}
        </View>)}
      </View>
      <View className="items-center gap-4 px-9 pb-3 pt-10"><Text className="text-[11px] tracking-[3px] text-teal-500">EQUI · APP</Text><Text className="text-center font-bold text-[24px] leading-[27px] text-teal-500">{c.closingTitle}</Text><Text className="mt-2 text-center text-[10px] tracking-[1px] text-ink-50">POWERED BY <Text className="font-semi text-teal-500">De Paardentherapeut</Text></Text></View>
    </ScrollView>

    <Modal visible={sheet !== null} transparent animationType="slide" onRequestClose={close}>
      <View style={{ flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(11,42,41,0.45)' }}>
        <Pressable accessibilityRole="button" accessibilityLabel="Sluiten" onPress={close} style={{ flex: 1 }} />
        <View accessibilityViewIsModal className="w-full self-center rounded-t-[28px] bg-canvas" style={{ maxWidth: 560, maxHeight: height - insets.top - 24, paddingBottom: Math.max(insets.bottom, 20) }}>
          <View className="flex-row items-center justify-between px-6 pt-4"><View className="h-1 w-10 rounded-full bg-ink-15" /><Pressable accessibilityRole="button" accessibilityLabel="Sluiten" onPress={close} className="h-11 w-11 items-center justify-center"><X size={22} color="#127A79" /></Pressable></View>
          <ScrollView contentContainerStyle={{ paddingHorizontal: 24, paddingBottom: 8 }}>
            {sheet === 'welcome' ? <View className="items-center gap-5"><View className="h-16 w-16 items-center justify-center rounded-full bg-mint-100"><Check size={32} color="#127A79" /></View><Text accessibilityRole="header" className="text-center font-bold text-[28px] text-teal-700">{c.welcomeTitle}</Text><Text className="text-center text-[16px] leading-[24px] text-ink-70">{c.welcomeBody}</Text><Button title="Naar de intake" onPress={() => { close(); onIntake(); }} /></View> :
              <View className="gap-5"><Text className="text-[10px] tracking-[2px] text-teal-500">BEVESTIG JE UPGRADE</Text><Text accessibilityRole="header" className="font-bold text-[28px] text-teal-700">{c.confirmationTitle}</Text><View className="gap-3 border-y border-ink-15 py-5"><View className="flex-row flex-wrap justify-between gap-2"><Text className="text-[16px] text-ink">{c.confirmationPlan}</Text><Text className="font-bold text-[16px] text-ink">{c.price}</Text></View><Text className="text-[15px] text-ink-70">{c.confirmationProtocol}</Text></View><Text className="text-[13px] leading-[19px] text-ink-70">Je start hiermee je intake. Je abonnement wordt nog niet geactiveerd.</Text><Button title={c.confirmationButton} onPress={() => setSheet('welcome')} /><Button title="Nog even niet" variant="text" onPress={close} /></View>}
          </ScrollView>
        </View>
      </View>
    </Modal>
  </>;
}

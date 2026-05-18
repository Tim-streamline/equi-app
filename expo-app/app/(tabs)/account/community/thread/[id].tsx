import { useState } from 'react';
import {
  View, Text, ScrollView, Pressable, TextInput, KeyboardAvoidingView, Platform,
} from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { MoreHorizontal, ThumbsUp, MessageCircle, Send } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Avatar } from '@/components/ui/Avatar';
import { Chip } from '@/components/ui/Chip';
import { TAB_BAR_BASE_HEIGHT } from '@/hooks/useTabBarPadding';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export default function ThreadScreen() {
  const [reply, setReply] = useState('');
  const insets = useSafeAreaInsets();
  const tabBarHeight = TAB_BAR_BASE_HEIGHT + insets.bottom;
  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Vraag"
          onBack={() => router.back()}
          right={
            <IconButton>
              <MoreHorizontal size={20} color="#1B2A2A" />
            </IconButton>
          }
        />
        <KeyboardAvoidingView
          style={{ flex: 1 }}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        >
          <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 30, gap: 12 }}>
            <View className="rounded-2xl border border-ink-8 bg-white p-4">
              <View className="flex-row items-center gap-3 mb-2">
                <Avatar initial="E" size={32} gradient={false} bg="#5FD7CB" />
                <View className="flex-1">
                  <Text className="font-semi text-ink text-[14px]">Esther M.</Text>
                  <Text className="text-[11px] text-ink-50">2 uur geleden</Text>
                </View>
              </View>
              <Text className="text-[14px] text-ink leading-[20px]">
                Mijn ruin krabt zijn manen al weken open. Voeding al aangepast, geen verbetering. Iemand ervaring met brandnetel-protocol?
              </Text>
              <View className="mt-3 flex-row gap-1.5">
                <Chip label="jeukklachten" variant="outline" />
                <Chip label="voeding" variant="outline" />
              </View>
            </View>

            <View className="rounded-2xl bg-mint-50 p-4">
              <View className="flex-row items-center gap-3 mb-2">
                <Avatar initial="S" size={32} gradient={false} bg="#0D5C5B" />
                <View className="flex-1">
                  <View className="flex-row items-center gap-2 flex-wrap">
                    <Text className="font-semi text-ink text-[14px]">Shelley</Text>
                    <View className="rounded-pill bg-white px-2 py-0.5">
                      <Text className="font-semi text-mint-700 text-[10px]">Therapeut</Text>
                    </View>
                  </View>
                  <Text className="text-[11px] text-ink-50">1 uur geleden</Text>
                </View>
              </View>
              <Text className="text-[14px] text-ink leading-[21px]">
                Hi Esther — als voeding al klopt is brandnetel zeker te proberen. Belangrijk: <Text className="font-italic">vers</Text>, niet gedroogd. Bouw op in 5 dagen. Stuur me eens een foto van zijn manen via de app, dan kijk ik mee.
              </Text>
              <View className="mt-3 flex-row gap-4">
                <View className="flex-row items-center gap-1.5">
                  <ThumbsUp size={13} color="rgba(27,42,42,0.5)" />
                  <Text className="text-[12px] text-ink-50">24</Text>
                </View>
                <View className="flex-row items-center gap-1.5">
                  <MessageCircle size={13} color="rgba(27,42,42,0.5)" />
                  <Text className="text-[12px] text-ink-50">4 reacties</Text>
                </View>
              </View>
            </View>

            <View className="rounded-2xl border border-ink-8 bg-white p-4">
              <View className="flex-row items-center gap-3 mb-2">
                <Avatar initial="J" size={32} gradient={false} bg="#5FD7CB" />
                <View className="flex-1">
                  <Text className="font-semi text-ink text-[14px]">Jolien K.</Text>
                  <Text className="text-[11px] text-ink-50">30 min geleden</Text>
                </View>
              </View>
              <Text className="text-[14px] text-ink leading-[20px]">
                Bij mijn merrie ook geholpen. Goed om Shelley&apos;s stappenplan te volgen — niet zelf experimenteren.
              </Text>
              <View className="mt-3 flex-row gap-4">
                <View className="flex-row items-center gap-1.5">
                  <ThumbsUp size={13} color="rgba(27,42,42,0.5)" />
                  <Text className="text-[12px] text-ink-50">5</Text>
                </View>
              </View>
            </View>
          </ScrollView>

          <View
            className="border-t border-ink-8 bg-white px-4 pt-3"
            style={{ paddingBottom: 12, marginBottom: tabBarHeight }}
          >
            <View className="flex-row items-center gap-2">
              <TextInput
                value={reply}
                onChangeText={setReply}
                placeholder="Schrijf een reactie..."
                placeholderTextColor="rgba(27,42,42,0.4)"
                className="flex-1 rounded-pill bg-canvas-2 px-4 py-3 font-sans text-[14px] text-ink"
              />
              <Pressable className="h-10 w-10 items-center justify-center rounded-full bg-mint-500">
                <Send size={16} color="#fff" />
              </Pressable>
            </View>
          </View>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

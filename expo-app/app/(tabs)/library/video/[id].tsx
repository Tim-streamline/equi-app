import { View, Text, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Bookmark } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryChapters,
  useLibraryItem,
  useTherapist,
} from '@/db/hooks';

export default function VideoScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const item = useLibraryItem(id ?? '');
  const chapters = useLibraryChapters(id ?? '');
  const therapist = useTherapist((item.authorTherapistId as string) || undefined);
  const padBottom = useTabBarPadding();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          onBack={() => router.back()}
          right={
            <IconButton>
              <Bookmark size={18} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="px-5 pt-5">
            <Text className="font-bold text-ink mb-2" style={{ fontSize: 24, lineHeight: 30 }}>
              {item.title as string}
            </Text>
            <View className="flex-row items-center gap-2">
              {therapist.name && (
                <>
                  <Text className="text-[12px] text-ink-50">Door {therapist.name as string}</Text>
                  <Text className="text-[12px] text-ink-50">·</Text>
                </>
              )}
              <Text className="text-[12px] text-ink-50">{item.durationLabel as string}</Text>
              {item.viewsLabel ? (
                <>
                  <Text className="text-[12px] text-ink-50">·</Text>
                  <Text className="text-[12px] text-ink-50">{item.viewsLabel as string}</Text>
                </>
              ) : null}
            </View>
          </View>

          {chapters.length > 0 && (
            <>
              <SectionTitle>Hoofdstukken</SectionTitle>
              <View className="px-4">
                {chapters.map((c: any, i: number) => {
                  const active = i === 0;
                  return (
                    <View
                      key={c.id}
                      className={`flex-row items-center justify-between rounded-xl p-3.5 ${active ? 'bg-mint-50' : ''}`}
                    >
                      <View className="flex-row items-center gap-3 flex-1">
                        <Text
                          className={`font-bold ${active ? 'text-mint-700' : 'text-ink-50'}`}
                          style={{ fontSize: 14, minWidth: 24 }}
                        >
                          {String(i + 1).padStart(2, '0')}
                        </Text>
                        <Text className="flex-1 text-[14px] text-ink font-medium">{c.title}</Text>
                      </View>
                      <Text className="text-[12px] text-ink-50" style={{ fontVariant: ['tabular-nums'] }}>
                        {c.startLabel}
                      </Text>
                    </View>
                  );
                })}
              </View>
            </>
          )}
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

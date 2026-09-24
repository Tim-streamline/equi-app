import { View, Text, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LibraryBookmarkButton } from '@/components/library/LibraryBookmarkButton';
import { RelatedLibraryItems } from '@/components/library/RelatedLibraryItems';
import { SubHeader } from '@/components/ui/SubHeader';
import { LibraryContent } from '@/components/library/LibraryContent';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryItem,
  useTherapist,
} from '@/db/hooks';

export default function VideoScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const item = useLibraryItem(id ?? '');
  const therapist = useTherapist((item.authorTherapistId as string) || undefined);
  const padBottom = useTabBarPadding();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          onBack={() => router.back()}
          right={
            <LibraryBookmarkButton key={id} itemId={id ?? ''} />
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

          <LibraryContent key={id} itemId={id ?? ''} />
          <RelatedLibraryItems itemId={id ?? ''} />
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

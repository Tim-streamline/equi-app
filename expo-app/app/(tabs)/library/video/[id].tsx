import { View, ScrollView } from 'react-native';
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
          <LibraryContent key={id} itemId={id ?? ''} preview={{ ...item, id: id ?? '', authorName: therapist.name as string | undefined }} />
          <RelatedLibraryItems itemId={id ?? ''} />
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

import { View, Text, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LibraryBookmarkButton } from '@/components/library/LibraryBookmarkButton';
import { RelatedLibraryItems } from '@/components/library/RelatedLibraryItems';
import { SubHeader } from '@/components/ui/SubHeader';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { LibraryContent } from '@/components/library/LibraryContent';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryItem,
  useTherapist,
} from '@/db/hooks';

export default function ArticleScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const item = useLibraryItem(id ?? '');
  const therapist = useTherapist((item.authorTherapistId as string) || undefined);
  const padBottom = useTabBarPadding();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Artikel"
          onBack={() => router.back()}
          right={
            <LibraryBookmarkButton key={id} itemId={id ?? ''} />
          }
        />
        <ScrollView contentContainerStyle={{ width: '100%', paddingBottom: padBottom }}>
          <View className="px-5">
            <Eyebrow className="mb-2">
              {`Lezen · ${item.durationLabel as string}${therapist.name ? ` · door ${therapist.name as string}` : ''}`}
            </Eyebrow>
            <Text className="font-bold text-ink mb-4" style={{ fontSize: 28, lineHeight: 32 }}>
              {item.title as string}
            </Text>
          </View>

          <LibraryContent key={id} itemId={id ?? ''} />
          <RelatedLibraryItems itemId={id ?? ''} />
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

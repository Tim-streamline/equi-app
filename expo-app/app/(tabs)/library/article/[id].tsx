import { View, Text, ScrollView, Image } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Bookmark, Plus } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryArticleSections,
  useLibraryItem,
  useTherapist,
} from '@/db/hooks';

export default function ArticleScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const item = useLibraryItem(id ?? '');
  const sections = useLibraryArticleSections(id ?? '');
  const therapist = useTherapist((item.authorTherapistId as string) || undefined);
  const padBottom = useTabBarPadding();

  const intro = sections.find((s: any) => !s.heading);
  const rest = sections.filter((s: any) => !!s.heading);

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Artikel"
          onBack={() => router.back()}
          right={
            <IconButton>
              <Bookmark size={18} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: padBottom }}>
          <View
            className="mb-5 overflow-hidden rounded-2xl"
            style={{ height: 200, backgroundColor: '#0D5C5B', position: 'relative', alignItems: 'center', justifyContent: 'center' }}
          >
            <Image
              source={require('@/assets/images/logo-horse-white.png')}
              style={{ width: 120, height: 120, opacity: 0.4, resizeMode: 'contain' }}
            />
            <View style={{ position: 'absolute', top: 12, left: 12 }}>
              <Chip variant="tag" label={(item.kind as string) ?? ''} />
            </View>
          </View>

          <Eyebrow className="mb-2">
            {`Lezen · ${item.durationLabel as string}${therapist.name ? ` · door ${therapist.name as string}` : ''}`}
          </Eyebrow>
          <Text className="font-bold text-ink mb-4" style={{ fontSize: 28, lineHeight: 32 }}>
            {item.title as string}
          </Text>

          {intro && (
            <Text className="text-ink mb-3.5" style={{ fontSize: 16, lineHeight: 26 }}>
              {intro.body as string}
            </Text>
          )}

          {rest.map((s: any) => (
            <View key={s.id}>
              <Text className="font-bold text-teal-700 mt-6 mb-2" style={{ fontSize: 17 }}>
                {s.heading}
              </Text>
              <Text className="text-ink-70 mb-3.5" style={{ fontSize: 15, lineHeight: 24 }}>
                {s.body}
              </Text>
            </View>
          ))}

          <View className="mt-6 rounded-2xl bg-mint-50 p-4">
            <View className="flex-row items-center gap-3">
              <View className="h-9 w-9 items-center justify-center rounded-xl bg-white">
                <Plus size={18} color="#0D5C5B" />
              </View>
              <View className="flex-1">
                <Text className="font-bold text-ink text-[14px]">Voeg toe aan protocol</Text>
                <Text className="text-[12px] text-ink-70">1 el vers door ruwvoer · 5 dagen</Text>
              </View>
            </View>
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

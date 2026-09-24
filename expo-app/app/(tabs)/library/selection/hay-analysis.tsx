import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ArrowLeft } from 'lucide-react-native';
import { LibraryCard } from '@/components/library/LibraryCard';
import { useLibraryResource } from '@/hooks/useLibraryResource';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import type { LibraryAccess, LibraryCardItem } from '@/lib/library';

export default function HayAnalysisSelection() {
  const { data, error, refresh } = useLibraryResource<{ items: LibraryCardItem[]; access: LibraryAccess }>('/selections/hay-analysis');
  const paddingBottom = useTabBarPadding();
  return <SafeAreaView edges={['top']} className="flex-1 bg-canvas">
    <ScrollView contentContainerStyle={{ paddingBottom }}>
      <View className="px-5 pb-5 pt-2">
        <Pressable accessibilityRole="button" accessibilityLabel="Terug" onPress={() => router.canGoBack() ? router.back() : router.replace('/(tabs)/(pager)/protocol')}
          className="mb-3 h-11 w-11 items-center justify-center"><ArrowLeft size={22} color="#127A79" /></Pressable>
        <Text className="font-bold text-[23px] leading-7 text-ink">Hooi laten analyseren of zelf testen</Text>
      </View>
      {error ? <Pressable accessibilityRole="button" onPress={() => void refresh()} className="px-5 py-6">
        <Text className="text-[14px] leading-5 text-ink-70">{error} Tik om opnieuw te proberen.</Text>
      </Pressable> : !data ? <ActivityIndicator color="#127A79" /> :
        <View className="flex-row flex-wrap justify-between gap-y-3 px-4">
          {data.items.map(item => <LibraryCard key={item.id} item={item} access={data.access} />)}
        </View>}
    </ScrollView>
  </SafeAreaView>;
}

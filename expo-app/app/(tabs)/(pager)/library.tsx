import { View, Text, ScrollView, Pressable, TextInput, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Search } from 'lucide-react-native';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import {
  useLibraryCategories,
  useLibraryItems,
  useValue,
} from '@/db/hooks';

export default function LibraryScreen() {
  const padBottom = useTabBarPadding();
  const categories = useLibraryCategories();
  const list = useLibraryItems();
  const placeholder = useValue('librarySearchPlaceholder') as string;

  return (
    <View className="flex-1">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="px-5 pb-3 pt-1.5">
            <View className="flex-row items-center gap-2.5 rounded-xl border border-ink-8 bg-white px-3.5 py-2.5">
              <Search size={18} color="rgba(27,42,42,0.5)" />
              <TextInput
                placeholder={placeholder}
                placeholderTextColor="rgba(27,42,42,0.4)"
                className="flex-1 font-sans text-[14px] text-ink"
              />
            </View>
          </View>

          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingBottom: 14 }}
          >
            {categories.map((c: any) => (
              <Chip key={c.id} label={c.label} variant={c.isDefault ? 'default' : 'outline'} />
            ))}
          </ScrollView>

          <SectionTitle>Voor jou · op basis van protocol</SectionTitle>
          <View className="px-4 gap-2">
            {list.map((a: any) => (
              <Pressable
                key={a.id}
                onPress={() =>
                  router.push({
                    pathname: a.format === 'video' ? '/(tabs)/library/video/[id]' : '/(tabs)/library/article/[id]',
                    params: { id: a.id },
                  } as any)
                }
                className="flex-row gap-3 rounded-2xl border border-ink-8 bg-white p-2.5"
              >
                <View
                  className="overflow-hidden rounded-xl"
                  style={{ width: 64, height: 64, backgroundColor: '#0D5C5B', alignItems: 'center', justifyContent: 'center' }}
                >
                  <Image
                    source={require('@/assets/images/logo-horse-white.png')}
                    style={{ width: 42, height: 42, opacity: 0.5, resizeMode: 'contain' }}
                  />
                </View>
                <View className="flex-1 justify-center">
                  <Text className="font-semi text-ink text-[14px]" numberOfLines={2}>
                    {a.title}
                  </Text>
                  <Text className="mt-0.5 text-[11px] text-ink-50">{a.durationLabel}</Text>
                </View>
              </Pressable>
            ))}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

import { View, Text, ScrollView, Pressable } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ThumbsUp, MessageCircle, Plus } from 'lucide-react-native';
import { AppHeader } from '@/components/ui/AppHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Chip } from '@/components/ui/Chip';
import { Avatar } from '@/components/ui/Avatar';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useCommunityCategories, useCommunityPosts, useCurrentUser } from '@/db/hooks';

export default function CommunityScreen() {
  const padBottom = useTabBarPadding();
  const user = useCurrentUser();
  const categories = useCommunityCategories();
  const posts = useCommunityPosts();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <AppHeader
            greet="Community"
            title="Vraag & deel"
            avatar={(user.avatarInitial as string) ?? 'M'}
            right={
              <IconButton>
                <Plus size={20} color="#1B2A2A" />
              </IconButton>
            }
          />

          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingBottom: 14 }}
          >
            {categories.map((c: any) => (
              <Chip key={c.id} label={c.label} variant={c.isDefault ? 'default' : 'outline'} />
            ))}
          </ScrollView>

          <View className="px-4 gap-2.5">
            {posts.map((t: any) => (
              <Pressable
                key={t.id}
                onPress={() => router.push({ pathname: '/(tabs)/account/community/thread/[id]', params: { id: t.id } } as any)}
                className="rounded-2xl border border-ink-8 bg-white p-4"
              >
                <View className="flex-row items-center gap-3 mb-2">
                  <Avatar initial={t.authorInitial} size={32} gradient={false} bg={t.authorAvatarColor || '#5FD7CB'} />
                  <View className="flex-1">
                    <View className="flex-row items-center gap-2">
                      <Text className="font-semi text-ink text-[14px]">{t.authorName}</Text>
                      {t.hasExpertReply && (
                        <View className="rounded-pill bg-mint-50 px-2 py-0.5">
                          <Text className="font-semi text-mint-700 text-[10px]">Shelley antwoordde</Text>
                        </View>
                      )}
                    </View>
                  </View>
                  <Text className="text-[11px] text-ink-50">{t.whenLabel}</Text>
                </View>
                <Text className="text-[14px] text-ink leading-[20px]">{t.body}</Text>
                <View className="mt-3 flex-row gap-4">
                  <View className="flex-row items-center gap-1.5">
                    <ThumbsUp size={13} color="rgba(27,42,42,0.5)" />
                    <Text className="text-[12px] text-ink-50">{t.likesCount}</Text>
                  </View>
                  <View className="flex-row items-center gap-1.5">
                    <MessageCircle size={13} color="rgba(27,42,42,0.5)" />
                    <Text className="text-[12px] text-ink-50">{t.repliesCount} reacties</Text>
                  </View>
                </View>
              </Pressable>
            ))}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

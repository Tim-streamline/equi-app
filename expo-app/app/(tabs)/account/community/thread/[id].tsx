import { useState } from 'react';
import {
  View, Text, ScrollView, Pressable, TextInput, KeyboardAvoidingView, Platform,
} from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { MoreHorizontal, ThumbsUp, MessageCircle, Send } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Avatar } from '@/components/ui/Avatar';
import { Chip } from '@/components/ui/Chip';
import { TAB_BAR_BASE_HEIGHT } from '@/hooks/useTabBarPadding';
import {
  useCommunityPost,
  useCommunityPostTags,
  useCommunityReplies,
  useCurrentUser,
  useStoreMutations,
} from '@/db/hooks';

export default function ThreadScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const post = useCommunityPost(id ?? '');
  const tags = useCommunityPostTags(id ?? '');
  const replies = useCommunityReplies(id ?? '');
  const user = useCurrentUser();
  const { addPostReply } = useStoreMutations();
  const [reply, setReply] = useState('');
  const insets = useSafeAreaInsets();
  const tabBarHeight = TAB_BAR_BASE_HEIGHT + insets.bottom;

  const submit = () => {
    if (!reply.trim() || !id) return;
    addPostReply(
      id,
      reply.trim(),
      user.id,
      (user.name as string) ?? 'Jij',
      (user.avatarInitial as string) ?? 'J',
    );
    setReply('');
  };

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
                <Avatar initial={(post.authorInitial as string) ?? '?'} size={32} gradient={false} bg={(post.authorAvatarColor as string) || '#5FD7CB'} />
                <View className="flex-1">
                  <Text className="font-semi text-ink text-[14px]">{post.authorName as string}</Text>
                  <Text className="text-[11px] text-ink-50">{post.whenLabel as string}</Text>
                </View>
              </View>
              <Text className="text-[14px] text-ink leading-[20px]">{post.body as string}</Text>
              {tags.length > 0 && (
                <View className="mt-3 flex-row gap-1.5">
                  {tags.map((t: any) => (
                    <Chip key={t.id} label={t.label} variant="outline" />
                  ))}
                </View>
              )}
            </View>

            {replies.map((r: any) => (
              <View
                key={r.id}
                className={`rounded-2xl p-4 ${r.authorIsExpert ? 'bg-mint-50' : 'border border-ink-8 bg-white'}`}
              >
                <View className="flex-row items-center gap-3 mb-2">
                  <Avatar
                    initial={r.authorInitial}
                    size={32}
                    gradient={false}
                    bg={r.authorAvatarColor || (r.authorIsExpert ? '#0D5C5B' : '#5FD7CB')}
                  />
                  <View className="flex-1">
                    <View className="flex-row items-center gap-2 flex-wrap">
                      <Text className="font-semi text-ink text-[14px]">{r.authorName}</Text>
                      {r.authorIsExpert && (
                        <View className="rounded-pill bg-white px-2 py-0.5">
                          <Text className="font-semi text-mint-700 text-[10px]">Therapeut</Text>
                        </View>
                      )}
                    </View>
                    <Text className="text-[11px] text-ink-50">{r.whenLabel}</Text>
                  </View>
                </View>
                <Text className="text-[14px] text-ink leading-[21px]">{r.body}</Text>
                {(r.likesCount > 0 || r.repliesCount > 0) && (
                  <View className="mt-3 flex-row gap-4">
                    <View className="flex-row items-center gap-1.5">
                      <ThumbsUp size={13} color="rgba(27,42,42,0.5)" />
                      <Text className="text-[12px] text-ink-50">{r.likesCount}</Text>
                    </View>
                    {r.repliesCount > 0 && (
                      <View className="flex-row items-center gap-1.5">
                        <MessageCircle size={13} color="rgba(27,42,42,0.5)" />
                        <Text className="text-[12px] text-ink-50">{r.repliesCount} reacties</Text>
                      </View>
                    )}
                  </View>
                )}
              </View>
            ))}
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
              <Pressable
                onPress={submit}
                className="h-10 w-10 items-center justify-center rounded-full bg-mint-500"
              >
                <Send size={16} color="#fff" />
              </Pressable>
            </View>
          </View>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

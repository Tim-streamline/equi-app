import { KeyboardViewport, KeyboardScrollView as ScrollView, KeyboardTextInput as TextInput } from '@/components/ui/KeyboardForm';
import { useState, useRef, useEffect, type ReactNode } from 'react';
import {
  View, Text, Pressable, Animated, Easing,
} from 'react-native';
import { router } from 'expo-router';
import { Send } from 'lucide-react-native';
import { useQuery } from '@powersync/react';
import { ShelbyAvatar } from '@/components/shelby/ShelbyAvatar';
import {
  SHELBY_AI_NOTICE, SHELBY_NAME, SHELBY_SUBTITLE, SHELBY_TYPING_MS, SHELBY_WELCOME,
  isSameLocalDay, mergeShelbyMessages, questionId, type FailedQuestion, type ShelbyChatMessage,
} from '@/lib/shelby';
import { askShelby } from '@/lib/shelby-api';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useHorseDashboard } from '@/hooks/useHorseDashboard';
import { useCurrentUserId, useChatMessages } from '@/db/hooks';

const newId = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
  ? crypto.randomUUID()
  : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
const wait = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

/** One of Shelby's messages: her avatar beside the bubble, optionally with her name above it. */
function ShelbyMessage({ children, named = false }: { children: ReactNode; named?: boolean }) {
  return <View className="max-w-[88%] flex-row items-end gap-2 self-start">
    <ShelbyAvatar size={28} />
    <View className="min-w-0 flex-shrink">
      {named && <Text className="mb-1 ml-1 font-semi text-[12px] text-mint-800">{SHELBY_NAME}</Text>}
      <View className="rounded-2xl rounded-bl-md bg-mint-50 px-3.5 py-2.5">{children}</View>
    </View>
  </View>;
}

/** Three dots that pulse one after another while Shelby is "typing". */
function TypingDots() {
  const dots = useRef([0, 1, 2].map(() => new Animated.Value(0.3))).current;
  useEffect(() => {
    const loop = Animated.loop(Animated.stagger(180, dots.map(dot => Animated.sequence([
      Animated.timing(dot, { toValue: 1, duration: 300, easing: Easing.out(Easing.quad), useNativeDriver: true }),
      Animated.timing(dot, { toValue: 0.3, duration: 300, easing: Easing.in(Easing.quad), useNativeDriver: true }),
    ]))));
    loop.start();
    return () => loop.stop();
  }, [dots]);
  return <View accessibilityLabel={`${SHELBY_NAME} is aan het typen`} className="flex-row gap-1 py-1">
    {dots.map((opacity, i) => <Animated.View key={i} style={{ opacity, width: 7, height: 7, borderRadius: 4, backgroundColor: 'rgba(27,42,42,0.55)' }} />)}
  </View>;
}

export default function NovaChatModal() {
  const insets = useSafeAreaInsets();
  const { data, horseId } = useHorseDashboard();
  const userId = useCurrentUserId();
  // Shelby starts a fresh chat per horse each day; the server creates it with the first question.
  const { data: sessions } = useQuery<{ id: string; started_at: string }>(
    'SELECT id, started_at FROM chat_sessions WHERE user_id = ? AND horse_id = ? ORDER BY started_at DESC LIMIT 1', [userId, horseId ?? '']);
  const [answeredSession, setAnsweredSession] = useState('');
  const today = sessions?.[0] && isSameLocalDay(sessions[0].started_at) ? sessions[0].id : '';
  const sessionId = answeredSession || today;
  const synced = useChatMessages(sessionId);
  const [pending, setPending] = useState<ShelbyChatMessage[]>([]);
  useEffect(() => { setPending([]); setAnsweredSession(''); }, [horseId]);
  const messages = mergeShelbyMessages(sessionId ? synced : [], pending);
  const [error, setError] = useState('');

  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const scrollRef = useRef<ScrollView>(null);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

  useEffect(() => {
    setTimeout(() => scrollRef.current?.scrollToEnd({ animated: true }), 100);
  }, [messages.length, busy]);

  // A question that failed keeps its id: if the server did answer before the connection dropped,
  // sending it again returns that answer instead of asking Shelby twice.
  const failed = useRef<FailedQuestion | null>(null);
  const ask = async () => {
    const question = input.trim();
    if (!question || busy || !horseId) return;
    const clientId = questionId(failed.current, question, horseId, newId);
    failed.current = null;
    setInput('');
    setError('');
    setPending(old => [...old, { id: clientId, role: 'user', body: question, plusCta: false }]);
    setBusy(true);
    const started = Date.now();
    try {
      const result = await askShelby({ horseId, clientId, body: question });
      // Shelby "types" for at least a few seconds, even when the answer is ready sooner.
      await wait(Math.max(0, SHELBY_TYPING_MS - (Date.now() - started)));
      if (!mounted.current) return;
      setAnsweredSession(result.sessionId);
      setPending(old => [...old.filter(m => m.id !== clientId), ...result.messages]);
    } catch (failure) {
      if (!mounted.current) return;
      // Give the question back so the owner can send it again with the same id.
      failed.current = { id: clientId, body: question, horseId };
      setPending(old => old.filter(m => m.id !== clientId));
      setInput(current => current || question);
      setError(failure instanceof Error ? failure.message : 'Shelby is even niet bereikbaar. Probeer het zo opnieuw.');
    } finally {
      if (mounted.current) setBusy(false);
    }
  };

  return (
    <KeyboardViewport style={{ flex: 1 }}>
    <Pressable
      style={{ flex: 1, backgroundColor: 'rgba(11,42,41,0.45)', justifyContent: 'flex-end' }}
      onPress={() => router.back()}
    >
      <Pressable
        onPress={() => {}}
        style={{
          backgroundColor: '#fff',
          borderTopLeftRadius: 28,
          borderTopRightRadius: 28,
          maxHeight: '78%',
          paddingTop: 8,
          paddingHorizontal: 20,
          paddingBottom: 16 + insets.bottom,
        }}
      >
        <View className="mx-auto mb-3 h-1 w-9 rounded-pill bg-ink-15" />
        <ScrollView ref={scrollRef} style={{ flexGrow: 0 }} contentContainerStyle={{ paddingBottom: 8 }}>
          <View className="mb-4 flex-row items-center gap-3">
            <ShelbyAvatar size={48} />
            <View className="min-w-0 flex-1">
              <Text accessibilityRole="header" className="font-bold text-ink" style={{ fontSize: 18 }}>{SHELBY_NAME}</Text>
              <Text className="text-[13px] text-ink-70">{SHELBY_SUBTITLE}</Text>
            </View>
          </View>

          <View style={{ gap: 10, paddingVertical: 4 }}>
            <ShelbyMessage named>
              <Text className="text-[14px] leading-[21px] text-ink">{SHELBY_WELCOME}</Text>
            </ShelbyMessage>
            <Text className="ml-9 text-[11px] leading-[16px] text-ink-50">{SHELBY_AI_NOTICE}</Text>
            {messages.map((m) => m.role === 'assistant' ? (
              <ShelbyMessage key={m.id}>
                <Text className="text-[14px] leading-[21px] text-ink">{m.body}</Text>
                {m.plusCta && (
                  <Pressable accessibilityRole="button" onPress={() => router.push('/protocol-purchase')}
                    className="mt-2.5 self-start rounded-pill bg-teal-700 px-4 py-2">
                    <Text className="font-semi text-[13px] text-white">Bekijk EquiBoost</Text>
                  </Pressable>
                )}
              </ShelbyMessage>
            ) : (
              <View key={m.id} className="max-w-[85%] self-end rounded-2xl rounded-br-md bg-teal-700 px-3.5 py-2.5">
                <Text className="text-[14px] leading-[21px] text-white">{m.body}</Text>
              </View>
            ))}
            {busy && <ShelbyMessage><TypingDots /></ShelbyMessage>}
          </View>

          {!!error && <Text accessibilityLiveRegion="polite" className="mt-2 text-[13px] leading-[19px] text-danger">{error}</Text>}
          <View className="mt-3 flex-row items-center gap-2">
            <TextInput
              value={input}
              onChangeText={setInput}
              onSubmitEditing={() => void ask()}
              placeholder={`Vraag iets over ${data?.horse.name ?? 'je paard'}...`}
              placeholderTextColor="rgba(27,42,42,0.4)"
              className="flex-1 rounded-pill bg-canvas-2 px-4 py-3 font-sans text-[14px] text-ink"
            />
            <Pressable
              onPress={() => void ask()}
              accessibilityRole="button" accessibilityLabel={`Verstuur je vraag aan ${SHELBY_NAME}`}
              disabled={busy || !horseId || !input.trim()}
              className={`h-10 w-10 items-center justify-center rounded-full ${busy ? 'bg-ink-15' : 'bg-mint-500'}`}
            >
              <Send size={16} color="#fff" />
            </Pressable>
          </View>
        </ScrollView>
      </Pressable>
    </Pressable>
    </KeyboardViewport>
  );
}

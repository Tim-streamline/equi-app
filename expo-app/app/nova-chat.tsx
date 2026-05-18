import { useState, useRef, useEffect } from 'react';
import {
  View, Text, Pressable, TextInput, ScrollView, KeyboardAvoidingView, Platform,
} from 'react-native';
import { router } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { Sparkles, Send } from 'lucide-react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

type Msg = { role: 'nova' | 'you'; text: string };

const FAKE_REPLIES = [
  'Goede vraag! Bij Nova zou ik beginnen met het versterken van de darmen — zie het brandnetel-artikel in de bibliotheek.',
  'Let bij voorjaarsrui op de combinatie lijnzaad + brandnetel. Bouw langzaam op.',
  'Vergeet niet dat huid en darmen samen werken — het is écht een holistisch verhaal.',
];

export default function NovaChatModal() {
  const insets = useSafeAreaInsets();
  const [msgs, setMsgs] = useState<Msg[]>([
    { role: 'nova', text: 'Hi Marit! Wat speelt er bij Nova?' },
  ]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const scrollRef = useRef<ScrollView>(null);

  useEffect(() => {
    setTimeout(() => scrollRef.current?.scrollToEnd({ animated: true }), 100);
  }, [msgs.length]);

  const ask = () => {
    if (!input.trim() || busy) return;
    const q = input.trim();
    setInput('');
    setMsgs((m) => [...m, { role: 'you', text: q }]);
    setBusy(true);
    setTimeout(() => {
      const reply = FAKE_REPLIES[Math.floor(Math.random() * FAKE_REPLIES.length)];
      setMsgs((m) => [...m, { role: 'nova', text: reply }]);
      setBusy(false);
    }, 900);
  };

  return (
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
        <KeyboardAvoidingView
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          keyboardVerticalOffset={20}
        >
          <View className="flex-row items-center gap-2.5 mb-3">
            <LinearGradient
              colors={['#30C7BA', '#0D5C5B']}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={{ width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center' }}
            >
              <Sparkles size={18} color="#fff" />
            </LinearGradient>
            <View>
              <Text className="font-bold text-ink" style={{ fontSize: 17 }}>Nova</Text>
              <Text className="text-[11px] text-ink-50">AI-assistent · getraind op Shelley&apos;s werk</Text>
            </View>
          </View>

          <ScrollView ref={scrollRef} style={{ maxHeight: 320 }} contentContainerStyle={{ gap: 8, paddingVertical: 4 }}>
            {msgs.map((m, i) => (
              <View
                key={i}
                className={`max-w-[85%] rounded-2xl px-3.5 py-2.5 ${
                  m.role === 'nova' ? 'bg-mint-50 self-start' : 'bg-teal-700 self-end'
                }`}
              >
                <Text
                  className={`text-[14px] leading-[20px] ${m.role === 'nova' ? 'text-ink' : 'text-white'}`}
                >
                  {m.text}
                </Text>
              </View>
            ))}
            {busy && (
              <View className="max-w-[85%] self-start rounded-2xl bg-mint-50 px-3.5 py-3 flex-row gap-1">
                <View className="h-1.5 w-1.5 rounded-full bg-ink-50" />
                <View className="h-1.5 w-1.5 rounded-full bg-ink-50" />
                <View className="h-1.5 w-1.5 rounded-full bg-ink-50" />
              </View>
            )}
          </ScrollView>

          <View className="mt-3 flex-row items-center gap-2">
            <TextInput
              value={input}
              onChangeText={setInput}
              onSubmitEditing={ask}
              placeholder="Vraag iets over Nova..."
              placeholderTextColor="rgba(27,42,42,0.4)"
              className="flex-1 rounded-pill bg-canvas-2 px-4 py-3 font-sans text-[14px] text-ink"
            />
            <Pressable
              onPress={ask}
              disabled={busy}
              className={`h-10 w-10 items-center justify-center rounded-full ${busy ? 'bg-ink-15' : 'bg-mint-500'}`}
            >
              <Send size={16} color="#fff" />
            </Pressable>
          </View>
        </KeyboardAvoidingView>
      </Pressable>
    </Pressable>
  );
}

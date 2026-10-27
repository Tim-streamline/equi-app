import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { MessageCircle, Sparkles } from 'lucide-react-native';
import { SHELBY_INTRO, SHELBY_INTRO_MS, SHELBY_NAME, claimShelbyIntro } from '@/lib/shelby';

const SIZE = 56;

/** Round turquoise chat button, with a one-time introduction from Shelby on the first visit. */
export function ShelbyChatButton({ bottom, onPress }: { bottom: number; onPress: () => void }) {
  const [intro, setIntro] = useState(false);
  useEffect(() => {
    let active = true;
    void claimShelbyIntro().then(first => { if (active && first) setIntro(true); });
    return () => { active = false; };
  }, []);
  useEffect(() => {
    if (!intro) return;
    const timer = setTimeout(() => setIntro(false), SHELBY_INTRO_MS);
    return () => clearTimeout(timer);
  }, [intro]);
  const open = () => { setIntro(false); onPress(); };

  return <>
    {intro && <Pressable accessibilityRole="button" accessibilityLabel={`${SHELBY_INTRO} Open de chat.`} accessibilityLiveRegion="polite"
      onPress={open}
      style={{ position: 'absolute', right: 20 + SIZE + 12, bottom: bottom + 2, maxWidth: 240,
        backgroundColor: '#FFFFFF', borderRadius: 16, borderBottomRightRadius: 4, paddingHorizontal: 14, paddingVertical: 10,
        elevation: 5, shadowColor: '#105C5B', shadowOpacity: 0.16, shadowRadius: 10, shadowOffset: { width: 0, height: 3 } }}>
      <Text style={{ fontSize: 14, lineHeight: 20, color: '#1B2A2A' }}>{SHELBY_INTRO}</Text>
    </Pressable>}
    <Pressable accessibilityRole="button" accessibilityLabel={`Chat met ${SHELBY_NAME}`} onPress={open}
      style={{ position: 'absolute', right: 20, bottom, width: SIZE, height: SIZE,
        alignItems: 'center', justifyContent: 'center', borderRadius: SIZE / 2, backgroundColor: '#127A79',
        elevation: 4, shadowColor: '#105C5B', shadowOpacity: 0.18, shadowRadius: 8, shadowOffset: { width: 0, height: 3 } }}>
      <View style={{ width: 28, height: 28, alignItems: 'center', justifyContent: 'center' }}>
        <MessageCircle size={28} color="#FFFFFF" fill="#FFFFFF" strokeWidth={1.75} />
        {/* A small spark inside the bubble marks Shelby as a smart assistant. */}
        <View pointerEvents="none" style={{ position: 'absolute', top: 7, left: 7 }}>
          <Sparkles size={14} color="#127A79" strokeWidth={2.25} />
        </View>
      </View>
    </Pressable>
  </>;
}

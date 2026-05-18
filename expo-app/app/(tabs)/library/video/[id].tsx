import { View, Text, ScrollView, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { Bookmark, Play } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { Chip } from '@/components/ui/Chip';
import { LIBRARY_FEATURED } from '@/data/mock';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';

const CHAPTERS = [
  { t: 'Wanneer brandnetel plukken', d: '0:00' },
  { t: 'Verse vs. gedroogde — wat werkt', d: '1:14' },
  { t: 'Doseren in vijf dagen', d: '2:32' },
  { t: 'Wanneer niet te geven', d: '4:10' },
];

export default function VideoScreen() {
  const padBottom = useTabBarPadding();
  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          onBack={() => router.back()}
          right={
            <IconButton>
              <Bookmark size={18} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="mx-4 overflow-hidden rounded-2xl" style={{ height: 260 }}>
            <LinearGradient
              colors={['#0D5C5B', '#083635']}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}
            >
              <Image
                source={require('@/assets/images/logo-horse-white.png')}
                style={{ width: 160, height: 160, opacity: 0.4, resizeMode: 'contain' }}
              />
              <View
                style={{
                  position: 'absolute',
                  width: 72, height: 72, borderRadius: 36,
                  backgroundColor: 'rgba(255,255,255,0.95)',
                  alignItems: 'center', justifyContent: 'center',
                }}
              >
                <Play size={28} color="#083635" fill="#083635" />
              </View>
              <View
                style={{
                  position: 'absolute', left: 14, right: 14, bottom: 30,
                  height: 3, backgroundColor: 'rgba(255,255,255,0.2)', borderRadius: 999,
                }}
              >
                <View style={{ width: '12%', height: '100%', backgroundColor: '#18BAB0', borderRadius: 999 }} />
              </View>
              <View
                style={{
                  position: 'absolute', left: 14, right: 14, bottom: 12,
                  flexDirection: 'row', justifyContent: 'space-between',
                }}
              >
                <Text className="font-semi text-white text-[12px]">0:00</Text>
                <Text className="font-semi text-white text-[12px]">5:24</Text>
              </View>
            </LinearGradient>
          </View>

          <View className="px-5 pt-5">
            <View className="self-start">
              <Chip label={LIBRARY_FEATURED.kind} />
            </View>
            <Text className="font-bold text-ink mt-2.5 mb-2" style={{ fontSize: 24, lineHeight: 30 }}>
              {LIBRARY_FEATURED.t}
            </Text>
            <View className="flex-row items-center gap-2">
              <Text className="text-[12px] text-ink-50">Door Shelley</Text>
              <Text className="text-[12px] text-ink-50">·</Text>
              <Text className="text-[12px] text-ink-50">{LIBRARY_FEATURED.dur}</Text>
              <Text className="text-[12px] text-ink-50">·</Text>
              <Text className="text-[12px] text-ink-50">1.2k gezien</Text>
            </View>
          </View>

          <SectionTitle>Hoofdstukken</SectionTitle>
          <View className="px-4">
            {CHAPTERS.map((c, i) => {
              const active = i === 0;
              return (
                <View
                  key={i}
                  className={`flex-row items-center justify-between rounded-xl p-3.5 ${active ? 'bg-mint-50' : ''}`}
                >
                  <View className="flex-row items-center gap-3 flex-1">
                    <Text
                      className={`font-bold ${active ? 'text-mint-700' : 'text-ink-50'}`}
                      style={{ fontSize: 14, minWidth: 24 }}
                    >
                      {String(i + 1).padStart(2, '0')}
                    </Text>
                    <Text className="flex-1 text-[14px] text-ink font-medium">{c.t}</Text>
                  </View>
                  <Text className="text-[12px] text-ink-50" style={{ fontVariant: ['tabular-nums'] }}>
                    {c.d}
                  </Text>
                </View>
              );
            })}
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

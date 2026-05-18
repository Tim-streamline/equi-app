import { View, Text, ScrollView, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Bookmark, Plus } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { Chip } from '@/components/ui/Chip';

export default function ArticleScreen() {
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
        <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: 120 }}>
          <View
            className="mb-5 overflow-hidden rounded-2xl"
            style={{ height: 200, backgroundColor: '#0D5C5B', position: 'relative', alignItems: 'center', justifyContent: 'center' }}
          >
            <Image
              source={require('@/assets/images/logo-horse-white.png')}
              style={{ width: 120, height: 120, opacity: 0.4, resizeMode: 'contain' }}
            />
            <View style={{ position: 'absolute', top: 12, left: 12 }}>
              <Chip variant="tag" label="Kruiden" />
            </View>
          </View>

          <Eyebrow className="mb-2">Lezen · 5 min · door Shelley</Eyebrow>
          <Text className="font-bold text-ink mb-4" style={{ fontSize: 28, lineHeight: 32 }}>
            Brandnetel — de juiste dosering voor jouw paard.
          </Text>
          <Text className="text-ink mb-3.5" style={{ fontSize: 16, lineHeight: 26 }}>
            Brandnetel is in <Text className="font-italic">mei en juni</Text> op zijn krachtigst. De jonge blaadjes bevatten silicium, ijzer en een mild ontstekingsremmende werking — perfect bij voorjaars-jeuk en milde manenklachten.
          </Text>

          <Text className="font-bold text-teal-700 mt-6 mb-2" style={{ fontSize: 17 }}>
            Hoeveel?
          </Text>
          <Text className="text-ink-70 mb-3.5" style={{ fontSize: 15, lineHeight: 24 }}>
            Begin met <Text className="font-italic">één eetlepel vers</Text> per dag, door het ruwvoer. Bouw in vijf dagen op naar 2–3 eetlepels, afhankelijk van het gewicht.
          </Text>

          <Text className="font-bold text-teal-700 mt-6 mb-2" style={{ fontSize: 17 }}>
            Niet doen.
          </Text>
          <Text className="text-ink-70 mb-3.5" style={{ fontSize: 15, lineHeight: 24 }}>
            Geen gedroogde brandnetel zonder broeien — dit verstoort de werking. En niet langer dan zes weken aan een stuk: bouw daarna af.
          </Text>

          <View className="mt-6 rounded-2xl bg-mint-50 p-4">
            <View className="flex-row items-center gap-3">
              <View className="h-9 w-9 items-center justify-center rounded-xl bg-white">
                <Plus size={18} color="#0D5C5B" />
              </View>
              <View className="flex-1">
                <Text className="font-bold text-ink text-[14px]">Voeg toe aan Nova&apos;s protocol</Text>
                <Text className="text-[12px] text-ink-70">1 el vers door ruwvoer · 5 dagen</Text>
              </View>
            </View>
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

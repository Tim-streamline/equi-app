import { View, Text, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Bookmark, Leaf } from 'lucide-react-native';
import { SubHeader } from '@/components/ui/SubHeader';
import { IconButton } from '@/components/ui/IconButton';
import { Coach } from '@/components/ui/Coach';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { ScoreRing } from '@/components/ui/ScoreRing';
import { Eyebrow } from '@/components/ui/Eyebrow';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { SCAN_RESULT } from '@/data/mock';
import { useTabBarPadding } from '@/hooks/useTabBarPadding';

const DOT: Record<string, string> = { good: '#2EA875', warn: '#D9A441', danger: '#C2543E' };
const CHIP: Record<string, 'success' | 'warn' | 'danger'> = {
  good: 'success',
  warn: 'warn',
  danger: 'danger',
};

export default function ScannerResultScreen() {
  const r = SCAN_RESULT;
  const chipVariant: 'success' | 'warn' | 'danger' =
    r.score >= 75 ? 'success' : r.score >= 50 ? 'warn' : 'danger';
  const padBottom = useTabBarPadding();

  return (
    <View className="flex-1 bg-canvas">
      <SafeAreaView edges={['top']} style={{ flex: 1 }}>
        <SubHeader
          title="Resultaat"
          onBack={() => router.back()}
          right={
            <IconButton>
              <Bookmark size={18} color="#1B2A2A" />
            </IconButton>
          }
        />
        <ScrollView contentContainerStyle={{ paddingBottom: padBottom }}>
          <View className="flex-row items-center gap-4 px-5 pb-5">
            <ScoreRing score={r.score} size={96} stroke={6} />
            <View className="flex-1">
              <Eyebrow>{r.brand}</Eyebrow>
              <Text className="mt-1 font-bold text-ink" style={{ fontSize: 18, lineHeight: 22 }}>
                {r.product}
              </Text>
              <View className="mt-2 self-start">
                <Chip label={r.rating} variant={chipVariant} />
              </View>
            </View>
          </View>

          <Coach tag="Advies voor Nova">{r.advice}</Coach>

          <SectionTitle>Ingrediënten ({r.ingredients.length})</SectionTitle>
          <View className="px-5 gap-2">
            {r.ingredients.map((ing, i) => (
              <View key={i} className="flex-row gap-3 rounded-2xl border border-ink-8 bg-white p-3.5">
                <View
                  className="mt-1.5 h-2.5 w-2.5 rounded-full"
                  style={{ backgroundColor: DOT[ing.tag] }}
                />
                <View className="flex-1">
                  <Text className="font-bold text-ink text-[14px]">{ing.nm}</Text>
                  <Text className="mt-0.5 text-[12.5px] text-ink-50 leading-[18px]">{ing.ds}</Text>
                </View>
              </View>
            ))}
          </View>

          <View className="p-5">
            <Button
              title="Bekijk natuurlijke alternatieven"
              variant="ghost"
              leading={<Leaf size={18} color="#1B2A2A" />}
              onPress={() => router.push({ pathname: '/(tabs)/library/article/[id]', params: { id: 'brandnetel' } } as any)}
            />
          </View>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}

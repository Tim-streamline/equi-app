import { View, Text, ScrollView } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
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
import { useTabBarPadding } from '@/hooks/useTabBarPadding';
import { useHorse, useScanIngredients, useScanResult, useScanResults } from '@/db/hooks';

const DOT: Record<string, string> = { good: '#2EA875', warn: '#D9A441', danger: '#C2543E' };

export default function ScannerResultScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const allScans = useScanResults();
  const targetId = id ?? allScans.find((s: any) => s.advice)?.id ?? allScans[0]?.id ?? '';
  const r = useScanResult(targetId);
  const ingredients = useScanIngredients(targetId);
  const horse = useHorse();
  const padBottom = useTabBarPadding();

  const score = (r.score as number) ?? 0;
  const chipVariant: 'success' | 'warn' | 'danger' = score >= 75 ? 'success' : score >= 50 ? 'warn' : 'danger';

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
            <ScoreRing score={score} size={96} stroke={6} />
            <View className="flex-1">
              <Eyebrow>{r.brand as string}</Eyebrow>
              <Text className="mt-1 font-bold text-ink" style={{ fontSize: 18, lineHeight: 22 }}>
                {r.productName as string}
              </Text>
              <View className="mt-2 self-start">
                <Chip label={r.rating as string} variant={chipVariant} />
              </View>
            </View>
          </View>

          {r.advice ? (
            <Coach tag={`Advies voor ${horse.name as string}`}>{r.advice as string}</Coach>
          ) : null}

          <SectionTitle>Ingrediënten ({ingredients.length})</SectionTitle>
          <View className="px-5 gap-2">
            {ingredients.map((ing: any) => (
              <View key={ing.id} className="flex-row gap-3 rounded-2xl border border-ink-8 bg-white p-3.5">
                <View
                  className="mt-1.5 h-2.5 w-2.5 rounded-full"
                  style={{ backgroundColor: DOT[ing.tag] }}
                />
                <View className="flex-1">
                  <Text className="font-bold text-ink text-[14px]">{ing.name}</Text>
                  <Text className="mt-0.5 text-[12.5px] text-ink-50 leading-[18px]">{ing.description}</Text>
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

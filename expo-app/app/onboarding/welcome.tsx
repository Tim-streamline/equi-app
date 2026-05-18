import { View, Text, Image } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { ArrowRight } from 'lucide-react-native';
import { Button } from '@/components/ui/Button';

export default function WelcomeScreen() {
  return (
    <View className="flex-1 bg-teal-900">
      <StatusBar style="light" />
      <SafeAreaView style={{ flex: 1 }}>
        <View className="flex-1 justify-between px-7 pb-7 pt-6">
          <View className="flex-row items-center gap-2.5">
            <View className="h-9 w-9 items-center justify-center rounded-xl bg-mint-500">
              <Image
                source={require('@/assets/images/logo-horse-white.png')}
                style={{ width: 22, height: 22, resizeMode: 'contain' }}
              />
            </View>
            <View>
              <Text className="font-bold text-white" style={{ fontSize: 18, letterSpacing: 0.5 }}>
                EquiNova
              </Text>
              <Text
                className="font-semi text-mint-200"
                style={{ fontSize: 10, letterSpacing: 0.6, textTransform: 'uppercase' }}
              >
                by De Paardentherapeut
              </Text>
            </View>
          </View>

          <View>
            <Text
              className="font-semi-italic text-mint-200 mb-3"
              style={{ fontSize: 14 }}
            >
              Paardengezondheid van de toekomst.
            </Text>
            <Text
              className="font-bold text-white mb-5"
              style={{ fontSize: 42, lineHeight: 44 }}
            >
              Ken je paard.{'\n'}Van binnenuit.
            </Text>
            <Text className="text-white/75" style={{ fontSize: 15, lineHeight: 24, maxWidth: 320 }}>
              Holistische ondersteuning — voeding, gedrag en symptomen op één plek. Begeleid door Shelley.
            </Text>
          </View>

          <View className="gap-2.5">
            <Button
              title="Begin met mijn paard"
              variant="primary"
              onPress={() => router.push('/onboarding/add-horse')}
              trailing={<ArrowRight size={18} color="#fff" />}
            />
            <Button
              title="Ik heb al een account"
              variant="ghost"
              className="border-white/20 bg-transparent"
              textClassName="text-white"
              onPress={() => router.push('/onboarding/add-horse')}
            />
          </View>
        </View>
      </SafeAreaView>
    </View>
  );
}

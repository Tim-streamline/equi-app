import { View, Text } from 'react-native';
import { ReactNode } from 'react';
import { router } from 'expo-router';
import { Settings } from 'lucide-react-native';
import { IconButton } from './IconButton';
import { ConnectionStatus } from './ConnectionStatus';

type Props = {
  title: string;
  right?: ReactNode;
};

export function AppHeader({ title, right }: Props) {
  return (
    <View className="flex-row items-center justify-between gap-3 px-5 pb-3.5 pt-1.5">
      <View className="flex-1">
        <Text className="font-bold text-[28px] leading-[30px] text-ink">{title}</Text>
      </View>
      <View className="flex-row items-center gap-2">
        <ConnectionStatus />
        {right}
        <IconButton size={38} onPress={() => router.navigate('/(tabs)/(pager)/account')}>
          <Settings size={20} color="#1B2A2A" />
        </IconButton>
      </View>
    </View>
  );
}

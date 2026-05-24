import { View, Text } from 'react-native';
import { ReactNode } from 'react';
import { Avatar } from './Avatar';
import { ConnectionStatus } from './ConnectionStatus';

type Props = {
  greet?: string;
  title: string;
  right?: ReactNode;
  avatar?: string;
};

export function AppHeader({ greet, title, right, avatar = 'M' }: Props) {
  return (
    <View className="flex-row items-center justify-between gap-3 px-5 pb-3.5 pt-1.5">
      <View className="flex-1">
        {greet ? <Text className="mb-1 text-[13px] text-ink-50">{greet}</Text> : null}
        <Text className="font-bold text-[28px] leading-[30px] text-ink">{title}</Text>
      </View>
      <View className="flex-row items-center gap-2">
        <ConnectionStatus />
        {right}
        <Avatar initial={avatar} size={38} />
      </View>
    </View>
  );
}

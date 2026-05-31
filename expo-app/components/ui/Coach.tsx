import { View, Text } from 'react-native';
import { ReactNode } from 'react';

type Props = { tag?: string; name?: string; children: ReactNode };

export function Coach({ tag, name = 'Shelley', children }: Props) {
  return (
    <View className="mx-4 mb-4 overflow-hidden rounded-card bg-teal-700 px-4 pl-[60px] py-[18px]">
      <View className="absolute top-[18px] left-4 h-8 w-8 items-center justify-center rounded-full bg-mint-500">
        <Text className="font-bold text-[13px] text-white">S</Text>
      </View>
      <View className="mb-3 flex-row items-baseline gap-2">
        <Text className="font-semi text-[14px] text-white">{name}</Text>
        {tag && (
          <Text className="font-semi text-[10px] uppercase text-mint-200" style={{ letterSpacing: 1.5 }}>
            · {tag}
          </Text>
        )}
      </View>
      <Text className="text-[14.5px] leading-[22px] text-white/90">{children}</Text>
    </View>
  );
}

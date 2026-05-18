import { View, Text } from 'react-native';
import { ReactNode } from 'react';
import { ChevronLeft } from 'lucide-react-native';
import { IconButton } from './IconButton';

type Props = {
  title?: string;
  onBack?: () => void;
  right?: ReactNode;
  tone?: 'light' | 'deep';
};

export function SubHeader({ title, onBack, right, tone = 'light' }: Props) {
  return (
    <View className="flex-row items-center justify-between gap-3 px-5 pb-3 pt-2">
      <IconButton tone={tone} onPress={onBack}>
        <ChevronLeft size={22} color={tone === 'deep' ? '#fff' : '#1B2A2A'} />
      </IconButton>
      <Text
        className={`flex-1 text-center font-semi text-[17px] ${tone === 'deep' ? 'text-white' : 'text-ink'}`}
        numberOfLines={1}
      >
        {title}
      </Text>
      <View style={{ width: 36, alignItems: 'flex-end' }}>{right}</View>
    </View>
  );
}

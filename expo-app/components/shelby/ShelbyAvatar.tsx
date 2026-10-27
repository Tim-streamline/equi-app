import { Image } from 'react-native';
import { SHELBY_NAME } from '@/lib/shelby';

const photo = require('@/assets/images/shelby-avatar.jpg');

export function ShelbyAvatar({ size = 28 }: { size?: number }) {
  return <Image source={photo} accessibilityLabel={`Profielfoto van ${SHELBY_NAME}`}
    style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: '#EAFBF9' }} />;
}

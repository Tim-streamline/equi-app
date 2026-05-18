import { View, Text, ViewStyle } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';

type Props = {
  initial?: string;
  size?: number;
  className?: string;
  gradient?: boolean;
  bg?: string;
  textClassName?: string;
};

export function Avatar({
  initial = 'M',
  size = 38,
  className = '',
  gradient = true,
  bg,
  textClassName = '',
}: Props) {
  const style: ViewStyle = { width: size, height: size, borderRadius: size / 2 };
  if (gradient && !bg) {
    return (
      <LinearGradient
        colors={['#99E8DF', '#108A82']}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={[style, { justifyContent: 'center', alignItems: 'center' }]}
      >
        <Text
          className={`font-semi text-white ${textClassName}`}
          style={{ fontSize: Math.round(size * 0.35) }}
        >
          {initial}
        </Text>
      </LinearGradient>
    );
  }
  return (
    <View
      className={`items-center justify-center ${className}`}
      style={[style, bg ? { backgroundColor: bg } : { backgroundColor: '#108A82' }]}
    >
      <Text className={`font-semi text-white ${textClassName}`} style={{ fontSize: Math.round(size * 0.35) }}>
        {initial}
      </Text>
    </View>
  );
}

import { View, Text } from 'react-native';
import Svg, { Circle } from 'react-native-svg';

type Props = { score: number; size?: number; stroke?: number };

function colorFor(score: number) {
  if (score >= 75) return '#2EA875';
  if (score >= 50) return '#D9A441';
  return '#C2543E';
}

export function ScoreRing({ score, size = 96, stroke = 6 }: Props) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const col = colorFor(score);
  return (
    <View style={{ width: size, height: size }} className="items-center justify-center">
      <Svg width={size} height={size} style={{ position: 'absolute', transform: [{ rotate: '-90deg' }] }}>
        <Circle cx={size / 2} cy={size / 2} r={r} stroke="rgba(27, 42, 42, 0.08)" strokeWidth={stroke} fill="none" />
        <Circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          stroke={col}
          strokeWidth={stroke}
          fill="none"
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={c * (1 - score / 100)}
        />
      </Svg>
      <View className="items-center">
        <Text className="font-bold text-ink" style={{ fontSize: size * 0.33, lineHeight: size * 0.33 }}>
          {score}
        </Text>
        {size >= 80 && (
          <Text className="mt-0.5 font-semi uppercase text-ink-50" style={{ fontSize: 11, letterSpacing: 1.1 }}>
            van 100
          </Text>
        )}
      </View>
    </View>
  );
}

import Svg, { Circle } from 'react-native-svg';

type Props = {
  value: number; // 0-100
  size?: number;
  stroke?: number;
  color?: string;
  trackColor?: string;
};

export function ProgressRing({
  value,
  size = 56,
  stroke = 5,
  color = '#18BAB0',
  trackColor = 'rgba(27, 42, 42, 0.08)',
}: Props) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  return (
    <Svg width={size} height={size} style={{ transform: [{ rotate: '-90deg' }] }}>
      <Circle cx={size / 2} cy={size / 2} r={r} stroke={trackColor} strokeWidth={stroke} fill="none" />
      <Circle
        cx={size / 2}
        cy={size / 2}
        r={r}
        stroke={color}
        strokeWidth={stroke}
        fill="none"
        strokeLinecap="round"
        strokeDasharray={c}
        strokeDashoffset={c - (c * Math.max(0, Math.min(100, value))) / 100}
      />
    </Svg>
  );
}

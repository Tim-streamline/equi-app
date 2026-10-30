import { Pressable, Switch, Text, View } from 'react-native';
import { selectionHaptic } from '@/lib/haptics';

type Props = {
  label: string;
  description?: string;
  value: boolean;
  disabled?: boolean;
  onChange: (value: boolean) => void;
  className?: string;
};

/** The whole row is the switch, so the touch target is not limited to the small native control. */
export function SwitchRow({ label, description, value, disabled = false, onChange, className = '' }: Props) {
  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityLabel={label}
      accessibilityState={{ checked: value, disabled }}
      disabled={disabled}
      onPress={() => { selectionHaptic(); onChange(!value); }}
      className={`min-h-11 flex-row items-center gap-3 ${disabled ? 'opacity-60' : ''} ${className}`}
    >
      <View className="flex-1">
        <Text className="font-semi text-[16px] text-ink">{label}</Text>
        {!!description && <Text className="mt-1 font-sans text-[14px] leading-[20px] text-ink-70">{description}</Text>}
      </View>
      {/* Visual only: the row above carries the role, state and press handling. */}
      <View pointerEvents="none" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <Switch value={value} disabled={disabled} focusable={false} trackColor={{ false: '#D8DEDA', true: '#18BAB0' }} ios_backgroundColor="#D8DEDA" thumbColor="#FFFFFF" />
      </View>
    </Pressable>
  );
}

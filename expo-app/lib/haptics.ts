import * as Haptics from 'expo-haptics';
import { Platform } from 'react-native';

// Subtle native feedback; browsers have no reliable equivalent, so web is a no-op.
const native = Platform.OS === 'ios' || Platform.OS === 'android';

/** A choice changed: toggles, checkboxes, segment switches. */
export function selectionHaptic() {
  if (native) void Haptics.selectionAsync().catch(() => {});
}

/** Something was sent or created. */
export function impactHaptic() {
  if (native) void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {});
}

/** A longer task finished successfully (scan result, intake submitted). */
export function successHaptic() {
  if (native) void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
}

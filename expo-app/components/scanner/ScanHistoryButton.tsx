import { router } from 'expo-router';
import { History } from 'lucide-react-native';
import { HEADER_ICON } from '@/components/ui/AppHeader';
import { IconButton } from '@/components/ui/IconButton';

/** Scan's header action: the scan history. */
export function ScanHistoryButton() {
  return (
    <IconButton accessibilityLabel="Scanhistorie" hitSlop={0} onPress={() => router.push('/(tabs)/scanner/history')}>
      <History size={22} color={HEADER_ICON} />
    </IconButton>
  );
}

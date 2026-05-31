import { Pressable, Text, View, Platform } from 'react-native';
import { router } from 'expo-router';
import { BlurView } from 'expo-blur';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Home, ClipboardList, ScanLine, BookOpen, User } from 'lucide-react-native';
import type { MaterialTopTabBarProps } from '@react-navigation/material-top-tabs';
import { ComponentType, ReactNode } from 'react';

type IconCmp = ComponentType<{ size?: number; color?: string; strokeWidth?: number }>;

const ICONS: Record<string, IconCmp> = {
  home: Home,
  protocol: ClipboardList,
  library: BookOpen,
  account: User,
};

const LABELS: Record<string, string> = {
  home: 'Home',
  protocol: 'Protocol',
  library: 'Bibliotheek',
  account: 'Account',
};

const ACTIVE = '#127A79';
const INACTIVE = 'rgba(27,42,42,0.5)';

function Cell({
  icon: Icon,
  label,
  focused,
  onPress,
}: {
  icon: IconCmp;
  label: string;
  focused: boolean;
  onPress: () => void;
}) {
  const color = focused ? ACTIVE : INACTIVE;
  return (
    <Pressable
      onPress={onPress}
      style={{ flex: 1, alignItems: 'center', justifyContent: 'center', gap: 2, paddingVertical: 4 }}
      hitSlop={8}
    >
      <Icon size={22} color={color} strokeWidth={focused ? 2.4 : 2} />
      <Text
        style={{
          fontSize: 10,
          color,
          fontFamily: focused ? 'SourceSans3_600SemiBold' : 'SourceSans3_500Medium',
          letterSpacing: 0.2,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

export function PagerTabBar({ state, navigation }: MaterialTopTabBarProps) {
  const insets = useSafeAreaInsets();

  const cells: ReactNode[] = [];
  state.routes.forEach((route, index) => {
    const Icon = ICONS[route.name];
    if (!Icon) return;
    const focused = state.index === index;
    const onPress = () => {
      const event = navigation.emit({
        type: 'tabPress',
        target: route.key,
        canPreventDefault: true,
      });
      if (!focused && !event.defaultPrevented) {
        navigation.navigate(route.name);
      }
    };
    cells.push(
      <Cell key={route.key} icon={Icon} label={LABELS[route.name]} focused={focused} onPress={onPress} />,
    );
  });

  // Inject the Scan action in the center — it is not a pager page; it pushes the
  // full-screen camera over the pager.
  const mid = Math.ceil(cells.length / 2);
  cells.splice(
    mid,
    0,
    <Cell
      key="scan-action"
      icon={ScanLine}
      label="Scan"
      focused={false}
      onPress={() => router.push('/(tabs)/scanner')}
    />,
  );

  return (
    <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0 }}>
      <BlurView
        intensity={Platform.OS === 'ios' ? 60 : 100}
        tint="light"
        style={{
          paddingHorizontal: 8,
          borderTopWidth: 1,
          borderTopColor: 'rgba(27,42,42,0.08)',
          backgroundColor: 'rgba(255,255,255,0.92)',
        }}
      >
        <View style={{ flexDirection: 'row', paddingTop: 8, paddingBottom: 8 }}>{cells}</View>
        <View style={{ height: insets.bottom }} />
      </BlurView>
    </View>
  );
}

import { Tabs } from 'expo-router';
import { Pressable, Text, View, Platform } from 'react-native';
import { BlurView } from 'expo-blur';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Home, ClipboardList, ScanLine, BookOpen, User } from 'lucide-react-native';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { ComponentType } from 'react';

type IconCmp = ComponentType<{ size?: number; color?: string; strokeWidth?: number }>;

const ICONS: Record<string, IconCmp> = {
  home: Home,
  protocol: ClipboardList,
  scanner: ScanLine,
  library: BookOpen,
  account: User,
};

const LABELS: Record<string, string> = {
  home: 'Home',
  protocol: 'Protocol',
  scanner: 'Scan',
  library: 'Bibliotheek',
  account: 'Account',
};

function CustomTabBar({ state, navigation }: BottomTabBarProps) {
  const insets = useSafeAreaInsets();
  return (
    <View
      style={{
        position: 'absolute',
        left: 0,
        right: 0,
        bottom: 0,
        paddingBottom: insets.bottom,
      }}
    >
      <BlurView
        intensity={Platform.OS === 'ios' ? 60 : 100}
        tint="light"
        style={{
          flexDirection: 'row',
          paddingHorizontal: 8,
          paddingTop: 8,
          paddingBottom: 6,
          borderTopWidth: 1,
          borderTopColor: 'rgba(27,42,42,0.08)',
          backgroundColor: 'rgba(255,255,255,0.92)',
        }}
      >
        {state.routes.map((route, index) => {
          const focused = state.index === index;
          const Icon = ICONS[route.name];
          if (!Icon) return null;
          const onPress = () => {
            const event = navigation.emit({
              type: 'tabPress',
              target: route.key,
              canPreventDefault: true,
            });
            if (!focused && !event.defaultPrevented) {
              navigation.navigate(route.name, route.params);
            }
          };
          const color = focused ? '#127A79' : 'rgba(27,42,42,0.5)';
          return (
            <Pressable
              key={route.key}
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
                {LABELS[route.name]}
              </Text>
            </Pressable>
          );
        })}
      </BlurView>
    </View>
  );
}

export default function TabsLayout() {
  return (
    <Tabs
      tabBar={(props) => <CustomTabBar {...props} />}
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: '#FBF8F3' } }}
    >
      <Tabs.Screen name="home" />
      <Tabs.Screen name="protocol" />
      <Tabs.Screen name="scanner" />
      <Tabs.Screen name="library" />
      <Tabs.Screen name="account" />
    </Tabs>
  );
}

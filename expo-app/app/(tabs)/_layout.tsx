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

function isScannerCameraFocused(state: BottomTabBarProps['state']): boolean {
  const focused = state.routes[state.index];
  if (focused.name !== 'scanner') return false;
  const nested = (focused as any).state;
  if (!nested) return true; // default landing on scanner = camera
  const inner = nested.routes[nested.index];
  return inner?.name === 'index';
}

function CustomTabBar({ state, navigation }: BottomTabBarProps) {
  const insets = useSafeAreaInsets();

  if (isScannerCameraFocused(state)) return null;

  return (
    <View
      style={{
        position: 'absolute',
        left: 0,
        right: 0,
        bottom: 0,
      }}
    >
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
        <View style={{ flexDirection: 'row', paddingTop: 8, paddingBottom: 8 }}>
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
                style={{
                  flex: 1,
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 2,
                  paddingVertical: 4,
                }}
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
        </View>
        <View style={{ height: insets.bottom }} />
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

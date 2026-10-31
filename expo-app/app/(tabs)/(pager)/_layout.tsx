import { useState } from 'react';
import { View } from 'react-native';
import { withLayoutContext } from 'expo-router';
import { createMaterialTopTabNavigator } from '@react-navigation/material-top-tabs';
import { PagerTabBar } from '@/components/navigation/PagerTabBar';
import { AppHeader, type PagerProgress } from '@/components/ui/AppHeader';

const { Navigator } = createMaterialTopTabNavigator();
const MaterialTopTabs = withLayoutContext(Navigator);

export default function PagerLayout() {
  // The tab bar reports the pager's scroll position so the header title can follow it.
  const [pager, setPager] = useState<PagerProgress | null>(null);
  return (
    // Static canvas painted once behind the pager. Scenes stay transparent and
    // navigation is handled exclusively through the bottom menu. Tabs mount on
    // first visit so app start only renders Home. The header is shared and owns
    // the top safe-area inset, so scenes start directly below it.
    <View style={{ flex: 1, backgroundColor: '#FBF8F3' }}>
      <AppHeader pager={pager} />
      <MaterialTopTabs
        tabBarPosition="bottom"
        tabBar={(props) => <PagerTabBar {...props} onPager={setPager} />}
        screenOptions={{ swipeEnabled: false, lazy: true, sceneStyle: { backgroundColor: 'transparent' } }}
      >
        <MaterialTopTabs.Screen name="home" />
        <MaterialTopTabs.Screen name="protocol" />
        <MaterialTopTabs.Screen name="library" />
        <MaterialTopTabs.Screen name="community" />
      </MaterialTopTabs>
    </View>
  );
}

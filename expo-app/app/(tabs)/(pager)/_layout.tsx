import { View } from 'react-native';
import { withLayoutContext } from 'expo-router';
import { createMaterialTopTabNavigator } from '@react-navigation/material-top-tabs';
import { PagerTabBar } from '@/components/navigation/PagerTabBar';

const { Navigator } = createMaterialTopTabNavigator();
const MaterialTopTabs = withLayoutContext(Navigator);

export default function PagerLayout() {
  return (
    // Static canvas painted once behind the pager. Scenes stay transparent and
    // navigation is handled exclusively through the bottom menu. Tabs mount on
    // first visit so app start only renders Home.
    <View style={{ flex: 1, backgroundColor: '#FBF8F3' }}>
      <MaterialTopTabs
        tabBarPosition="bottom"
        tabBar={(props) => <PagerTabBar {...props} />}
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

import { View } from 'react-native';
import { withLayoutContext } from 'expo-router';
import { createMaterialTopTabNavigator } from '@react-navigation/material-top-tabs';
import { PagerTabBar } from '@/components/navigation/PagerTabBar';

const { Navigator } = createMaterialTopTabNavigator();
const MaterialTopTabs = withLayoutContext(Navigator);

export default function PagerLayout() {
  return (
    // Static canvas painted once behind the pager — scenes are transparent so
    // only the content slides during a swipe, not the background.
    <View style={{ flex: 1, backgroundColor: '#FBF8F3' }}>
      <MaterialTopTabs
        tabBarPosition="bottom"
        tabBar={(props) => <PagerTabBar {...props} />}
        screenOptions={{ swipeEnabled: true, lazy: false, sceneStyle: { backgroundColor: 'transparent' } }}
      >
        <MaterialTopTabs.Screen name="home" />
        <MaterialTopTabs.Screen name="protocol" />
        <MaterialTopTabs.Screen name="library" />
        <MaterialTopTabs.Screen name="account" />
      </MaterialTopTabs>
    </View>
  );
}

import { View } from 'react-native';
import { withLayoutContext } from 'expo-router';
import { createMaterialTopTabNavigator, type MaterialTopTabNavigationOptions } from '@react-navigation/material-top-tabs';
import { PagerTabBar } from '@/components/navigation/PagerTabBar';
import { AppHeader } from '@/components/ui/AppHeader';
import { ConnectionStatus } from '@/components/ui/ConnectionStatus';
import { MutedAuthorsButton } from '@/components/community/CommunityFeed';
import type { PagerScreenOptions } from '@/components/navigation/headerActions';
import { BRAND_NAME } from '@/constants/brand';

const { Navigator } = createMaterialTopTabNavigator();
const MaterialTopTabs = withLayoutContext(Navigator);

// Header title and fixed header action per page; Protocol and Bibliotheek add
// their state-dependent actions themselves (`useHeaderAction`).
type Options = MaterialTopTabNavigationOptions & PagerScreenOptions;
const HOME: Options = { title: BRAND_NAME, headerAction: () => <ConnectionStatus /> };
const PROTOCOL: Options = { title: 'Protocol' };
const LIBRARY: Options = { title: 'Bibliotheek' };
const COMMUNITY: Options = { title: 'Community', headerAction: () => <MutedAuthorsButton /> };

export default function PagerLayout() {
  return (
    // Static canvas painted once behind the pager. Scenes stay transparent and
    // navigation is handled exclusively through the bottom menu. Tabs mount on
    // first visit so app start only renders Home. The tab bar slot sits above
    // the pages: it renders the shared header there (which owns the top
    // safe-area inset) and the bottom menu, which positions itself at the bottom.
    <View style={{ flex: 1, backgroundColor: '#FBF8F3' }}>
      <MaterialTopTabs
        tabBarPosition="top"
        tabBar={(props) => <><AppHeader {...props} /><PagerTabBar {...props} /></>}
        screenOptions={{ swipeEnabled: false, lazy: true, sceneStyle: { backgroundColor: 'transparent' } }}
      >
        <MaterialTopTabs.Screen name="home" options={HOME} />
        <MaterialTopTabs.Screen name="protocol" options={PROTOCOL} />
        <MaterialTopTabs.Screen name="library" options={LIBRARY} />
        <MaterialTopTabs.Screen name="community" options={COMMUNITY} />
      </MaterialTopTabs>
    </View>
  );
}

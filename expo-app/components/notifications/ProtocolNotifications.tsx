import { useEffect, useRef } from 'react';
import { AppState, Platform } from 'react-native';
import { router, useRootNavigationState } from 'expo-router';
import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useDb } from '@/db/provider';
import { useCurrentUser } from '@/db/hooks';
import { dashboardRequest, deviceTimezone } from '@/hooks/useHorseDashboard';
import { accountSession } from '@/lib/account-session';
import { phaseNotificationRoute } from '@/lib/protocol-notifications';

Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldPlaySound: true, shouldSetBadge: false, shouldShowBanner: true, shouldShowList: true }),
});

export function ProtocolNotifications() {
  const { currentUserId, isLoggedIn } = useDb();
  const user = useCurrentUser();
  const navigation = useRootNavigationState();
  const handling = useRef(new Set<string>());

  useEffect(() => {
    if (!isLoggedIn || !currentUserId) return;
    const revision = accountSession.revision;
    let cancelled = false;
    const saveToken = (token: string | null) => accountSession.forSession(revision, async () => {
      if (!cancelled) await dashboardRequest('/api/notifications/push-token', { token, timezone: deviceTimezone() });
    });
    let registering = false;
    const register = async () => {
      if (registering || user.notificationsOn === undefined) return;
      registering = true;
      try {
        if (!user.notificationsOn) {
          await saveToken(null);
          return;
        }
        const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId ?? process.env.EXPO_PUBLIC_EAS_PROJECT_ID;
        if (!projectId) {
          if (__DEV__) console.warn('[notifications] Configure an EAS project ID and native push credentials to enable reminders.');
          return;
        }
        if (Platform.OS === 'android') {
          await Notifications.setNotificationChannelAsync('protocol', { name: 'Protocol', importance: Notifications.AndroidImportance.DEFAULT });
        }
        let permission = await Notifications.getPermissionsAsync();
        if (!permission.granted && permission.canAskAgain) permission = await Notifications.requestPermissionsAsync();
        if (!permission.granted) {
          if (!cancelled) await saveToken(null);
          return;
        }
        const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
        if (!cancelled) await saveToken(token);
      } catch (error) {
        if (__DEV__) console.warn('[notifications] Registration will retry on resume.', error);
      } finally {
        registering = false;
      }
    };
    void register();
    const listener = AppState.addEventListener('change', (state) => { if (state === 'active') void register(); });
    const tokenListener = Notifications.addPushTokenListener(() => { void register(); });
    return () => { cancelled = true; listener.remove(); tokenListener.remove(); };
  }, [currentUserId, isLoggedIn, user.notificationsOn]);

  useEffect(() => {
    if (!isLoggedIn || !currentUserId || !navigation?.key) return;
    let cancelled = false;
    const open = async (response: Notifications.NotificationResponse) => {
      const id = response.notification.request.identifier;
      const route = phaseNotificationRoute(response.notification.request.content.data, currentUserId, id);
      if (!route || handling.current.has(id)) return;
      handling.current.add(id);
      const key = `phase-notification:last:${currentUserId}`;
      try {
        if (await AsyncStorage.getItem(key) === id || cancelled) return;
        router.push(route);
        await AsyncStorage.setItem(key, id);
        await Notifications.clearLastNotificationResponseAsync();
      } catch (error) {
        if (__DEV__) console.warn('[notifications] Could not open phase preview.', error);
      } finally { handling.current.delete(id); }
    };
    const last = Notifications.getLastNotificationResponse();
    if (last) void open(last);
    const listener = Notifications.addNotificationResponseReceivedListener((response) => { void open(response); });
    return () => { cancelled = true; listener.remove(); };
  }, [currentUserId, isLoggedIn, navigation?.key]);
  return null;
}

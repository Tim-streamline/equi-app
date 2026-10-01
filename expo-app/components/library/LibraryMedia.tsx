import { useCallback, useEffect, useMemo } from 'react';
import { Platform, Pressable, Text, View } from 'react-native';
import { useEvent } from 'expo';
import { useFocusEffect } from 'expo-router';
import { useVideoPlayer, VideoView, type VideoPlayer } from 'expo-video';
import { Pause, Play, RotateCcw, RotateCw } from 'lucide-react-native';

export type LibraryMediaMetadata = { title?: string; artist?: string; artwork?: string };
const players = new Set<VideoPlayer>();

/** Native media sessions own background playback; no JS timers drive playback. */
export function registerLibraryPlayer(player: VideoPlayer) {
  players.add(player);
  const subscription = player.addListener('playingChange', ({ isPlaying }) => {
    if (isPlaying) {
      for (const other of players) if (other !== player) other.pause();
    }
  });
  return () => { subscription.remove(); players.delete(player); };
}

const clock = (seconds: number) => {
  const value = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0));
  return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
};

export function LibraryMedia({ url, audio = false, metadata }: { url: string; audio?: boolean; metadata?: LibraryMediaMetadata }) {
  const source = useMemo(() => ({ uri: url, metadata: {
    title: metadata?.title || (audio ? 'Bibliotheekaudio' : 'Bibliotheekvideo'),
    artist: metadata?.artist || 'Equi App',
    ...(metadata?.artwork ? { artwork: metadata.artwork } : {}),
  } }), [url, audio, metadata?.title, metadata?.artist, metadata?.artwork]);
  const player = useVideoPlayer(source, value => {
    value.staysActiveInBackground = true;
    value.showNowPlayingNotification = true;
    value.audioMixingMode = 'auto';
    value.timeUpdateEventInterval = 1;
  });
  useEffect(() => registerLibraryPlayer(player), [player]);
  // Navigating away ends this item's session. Backgrounding the app does not blur the route.
  useFocusEffect(useCallback(() => {
    player.showNowPlayingNotification = true;
    return () => {
      try {
        player.pause();
        player.showNowPlayingNotification = false;
      } catch (error) {
        // On a native-stack pop (or source replacement), useVideoPlayer can
        // release first. Release already stops playback and removes the session.
        if ((error as { code?: string })?.code !== 'ERR_USING_RELEASED_SHARED_OBJECT') throw error;
      }
    };
  }, [player]));
  const { isPlaying } = useEvent(player, 'playingChange', { isPlaying: player.playing });
  const { status, error } = useEvent(player, 'statusChange', { status: player.status });
  const time = useEvent(player, 'timeUpdate');
  const currentTime = time?.currentTime ?? player.currentTime;
  const loaded = useEvent(player, 'sourceLoad');
  const duration = loaded?.duration ?? player.duration;
  const seek = (offset: number) => { player.currentTime = Math.max(0, Math.min(duration || 0, player.currentTime + offset)); };
  const toggle = () => {
    if (player.playing) player.pause();
    else {
      if (duration > 0 && player.currentTime >= duration - 0.1) player.currentTime = 0;
      player.play();
    }
  };
  return <View className="mb-5 w-full overflow-hidden rounded-2xl bg-mint-50">
    {audio && Platform.OS === 'web' && <VideoView player={player} nativeControls={false}
      style={{ position: 'absolute', width: 1, height: 1, opacity: 0 }} />}
    {!audio ? <VideoView player={player} nativeControls contentFit="contain"
      fullscreenOptions={{ enable: true, orientation: 'landscape' }}
      style={{ width: '100%', aspectRatio: 16 / 9, backgroundColor: '#0D5C5B' }} />
      : <View className="gap-3 p-4">
        <Text className="font-semi text-[16px] text-ink">{source.metadata.title}</Text>
        <View className="flex-row items-center justify-between gap-3">
          <Pressable accessibilityRole="button" accessibilityLabel="15 seconden terug" disabled={!duration} onPress={() => seek(-15)} className="items-center p-3">
            <RotateCcw size={23} color="#0D5C5B" /><Text className="text-xs text-ink">−15 s</Text>
          </Pressable>
          <Pressable accessibilityRole="button" accessibilityLabel={isPlaying ? 'Audio pauzeren' : 'Audio afspelen'} disabled={status === 'error'} onPress={toggle} className="rounded-full bg-teal-700 p-4">
            {isPlaying ? <Pause size={24} color="white" /> : <Play size={24} color="white" />}
          </Pressable>
          <Pressable accessibilityRole="button" accessibilityLabel="15 seconden vooruit" disabled={!duration} onPress={() => seek(15)} className="items-center p-3">
            <RotateCw size={23} color="#0D5C5B" /><Text className="text-xs text-ink">+15 s</Text>
          </Pressable>
        </View>
        <Text className="text-center text-sm text-ink-70">{clock(currentTime)} / {clock(duration)}</Text>
      </View>}
    {status === 'loading' && <Text className="p-3 text-sm text-ink-70">Media laden…</Text>}
    {!!error && <Text accessibilityRole="alert" className="p-3 text-sm text-red-700">Deze media kan niet worden afgespeeld. Open het item opnieuw om het nogmaals te proberen.</Text>}
  </View>;
}

import type { NativeScrollEvent, NativeSyntheticEvent, ScrollView } from 'react-native';
import { useCallback, useRef, type ReactNode, type Ref } from 'react';
import { KeyboardViewport, KeyboardScrollView } from '../ui/KeyboardForm';
import { rememberedScroll, rememberScroll } from '@/lib/intake/scroll-memory';

export { IntakeInputFrame, IntakeTextInput } from './IntakeInputFrame';

/**
 * With a `memoryKey` the page remembers where the owner was. If the screen is rebuilt
 * while she is typing (for example after a sync), it returns to the same spot instead of the top.
 */
export function IntakeScrollView({ children, footer, scrollRef, memoryKey }: { children: ReactNode; footer?: ReactNode; scrollRef?: Ref<ScrollView>; memoryKey?: string }) {
  const scroll = useRef<ScrollView | null>(null);
  const restore = useRef<number | null>(memoryKey ? rememberedScroll(memoryKey) : null);
  const attach = useCallback((value: ScrollView | null) => {
    scroll.current = value;
    if (typeof scrollRef === 'function') scrollRef(value);
    else if (scrollRef) (scrollRef as { current: ScrollView | null }).current = value;
  }, [scrollRef]);
  const onScroll = useCallback((event: NativeSyntheticEvent<NativeScrollEvent>) => {
    const y = event.nativeEvent.contentOffset.y;
    // The first layout reports the top; any real scroll (by the owner or the restore) takes over.
    if (restore.current !== null && y < 1) return;
    restore.current = null;
    if (memoryKey) rememberScroll(memoryKey, y);
  }, [memoryKey]);
  const onContentSizeChange = useCallback((_width: number, height: number) => {
    const y = restore.current;
    // Wait until the page is tall enough to reach the remembered spot.
    if (y === null || height < y) return;
    restore.current = null;
    scroll.current?.scrollTo({ y, animated: false });
  }, []);
  return <KeyboardViewport style={{ flex: 1 }}>
    <KeyboardScrollView ref={attach} onScroll={onScroll} onContentSizeChange={onContentSizeChange} style={{ flex: 1 }} contentContainerStyle={{ paddingHorizontal: 20, paddingTop: 16, paddingBottom: 24 }}>
      {children}
    </KeyboardScrollView>
    {footer}
  </KeyboardViewport>;
}

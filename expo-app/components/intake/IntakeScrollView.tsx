import type { ScrollView } from 'react-native';
import type { ReactNode, Ref } from 'react';
import { KeyboardViewport, KeyboardScrollView } from '../ui/KeyboardForm';

export { IntakeInputFrame, IntakeTextInput } from './IntakeInputFrame';

export function IntakeScrollView({ children, footer, scrollRef }: { children: ReactNode; footer?: ReactNode; scrollRef?: Ref<ScrollView> }) {
  return <KeyboardViewport style={{ flex: 1 }}>
    <KeyboardScrollView ref={scrollRef} style={{ flex: 1 }} contentContainerStyle={{ paddingHorizontal: 20, paddingTop: 16, paddingBottom: 24 }}>
      {children}
    </KeyboardScrollView>
    {footer}
  </KeyboardViewport>;
}

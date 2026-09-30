import type { ReactNode } from 'react';
import { KeyboardViewport, KeyboardScrollView } from '../ui/KeyboardForm';

export { KeyboardTextInput as IntakeTextInput } from '../ui/KeyboardForm';

export function IntakeScrollView({ children, footer }: { children: ReactNode; footer: ReactNode }) {
  return <KeyboardViewport style={{ flex: 1 }}>
    <KeyboardScrollView style={{ flex: 1 }} contentContainerStyle={{ paddingHorizontal: 20, paddingTop: 16, paddingBottom: 24 }}>
      {children}
    </KeyboardScrollView>
    {footer}
  </KeyboardViewport>;
}

import type { ReactNode } from 'react';
import { ActivityIndicator, Modal, Platform, Text, View } from 'react-native';
import { Button } from '@/components/ui/Button';
import type { SyncResetState } from '@/db/use-sync-reset';

export function SyncResetBoundary({ state, retry, onSignOut, children }: { state: SyncResetState; retry: () => void; onSignOut: () => void; children: ReactNode }) {
  return <>
    {state.detached ? null : children}
    <Modal visible={state.busy || !!state.error} animationType="fade" onRequestClose={() => {}}>
      <View className="flex-1 items-center justify-center bg-canvas px-6">
        <View className="w-full max-w-md gap-5">
          <Text className="font-heading text-2xl text-ink text-center">Synchronisatie herstellen</Text>
          {state.error ? <>
            <Text accessibilityRole="alert" className="font-body text-base text-ink text-center">{state.error}</Text>
            {state.restartRequired ? <>
              <Text className="font-body text-base text-ink text-center">{Platform.OS === 'web'
                ? 'Laad deze pagina opnieuw. Daarna gaan we verder met het herstellen van je online gegevens.'
                : 'Sluit de app volledig via het overzicht van recente apps en open hem opnieuw. Daarna gaan we verder met het herstellen van je online gegevens.'}</Text>
              {Platform.OS === 'web' && <Button title="Pagina opnieuw laden" onPress={() => window.location.reload()} />}
            </> : <>
              <Button title="Opnieuw proberen" onPress={retry} />
              <Button title="Uitloggen en opnieuw inloggen" variant="ghost" onPress={onSignOut} />
            </>}
          </> : <>
            <ActivityIndicator size="large" color="#127A79" accessibilityLabel="Synchronisatie herstellen" />
            <Text className="font-body text-base text-ink text-center">We laden je online gegevens opnieuw. Houd de app open.</Text>
          </>}
        </View>
      </View>
    </Modal>
  </>;
}

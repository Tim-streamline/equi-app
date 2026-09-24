import { Alert } from 'react-native';
// React Native Web's Alert is a no-op. Keep destructive actions and credit
// purchases behind the same confirmations used by the shared native screens.
Alert.alert = (title, message, buttons, options) => {
  const choices = buttons ?? [{ text: 'OK' }];
  const text = [title, message].filter(Boolean).join('\n\n');
  if (choices.length <= 1) {
    window.alert(text);
    choices[0]?.onPress?.();
    return;
  }
  const cancel = choices.find(button => button.style === 'cancel');
  const action = choices.find(button => button.style !== 'cancel');
  if (window.confirm(text)) action?.onPress?.();
  else if (cancel) cancel.onPress?.();
  else options?.onDismiss?.();
};

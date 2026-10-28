export { launchImageLibraryAsync } from 'expo-image-picker';

// Native picker URIs are managed by Expo/the OS.
export function releasePickedImages(_assets: readonly { uri: string }[]) {}

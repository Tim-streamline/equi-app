const path = require('node:path');
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');
const shared = path.resolve(__dirname, '../expo-app');
const config = getDefaultConfig(__dirname);
config.watchFolders = [shared];
config.resolver.disableHierarchicalLookup = true;
config.resolver.nodeModulesPaths = [path.resolve(__dirname, 'node_modules'), path.resolve(__dirname, 'node_modules/expo/node_modules')];
config.transformer.babelTransformerPath = require.resolve('react-native-svg-transformer/expo');
config.resolver.assetExts = config.resolver.assetExts.filter(ext => ext !== 'svg');
config.resolver.sourceExts.push('svg');
const overrides = new Map([
  ['components/notifications/BrowserPushSettings.tsx', 'components/BrowserPush.tsx'],
  ['db/provider.web.tsx', 'db/provider.tsx'],
  ['db/provider.tsx', 'db/provider.tsx'],
  ['db/auth.ts', 'db/auth.ts'],
  ['db/connector.ts', 'db/connector.ts'],
  ['lib/error-tracking.ts', 'components/error-tracking.ts'],
  ['app/_layout.tsx', 'components/RootLayout.tsx'],
  ['components/community/MediaGallery.tsx', 'components/MediaGallery.tsx'],
].map(([source, target]) => [path.join(shared, source), path.resolve(__dirname, target)]));
// Shared app code gets brand defaults for Text and Pressable (see that module and
// ../expo-app/metro.config.js for why it sits alone in its directory).
const uiDefaultsDir = path.join(shared, 'components/react-native-defaults');
const uiDefaults = path.join(uiDefaultsDir, 'index.tsx');
const appSource = [shared, path.resolve(__dirname, 'components')].map(dir => dir + path.sep);
config.resolver.resolveRequest = (context, moduleName, platform) => {
  if (moduleName === '@powersync/react-native') moduleName = '@powersync/common';
  const origin = context.originModulePath;
  if (moduleName === 'react-native' && path.dirname(origin) !== uiDefaultsDir && appSource.some(dir => origin.startsWith(dir)) && !origin.includes(`${path.sep}node_modules${path.sep}`)) {
    moduleName = uiDefaults;
  }
  if (moduleName.startsWith('@/')) moduleName = path.join(shared, moduleName.slice(2));
  const resolved = context.resolveRequest(context, moduleName, platform);
  if (resolved.type === 'sourceFile' && overrides.has(resolved.filePath)) {
    return { ...resolved, filePath: overrides.get(resolved.filePath) };
  }
  return resolved;
};
module.exports = withNativeWind(config, { input: './global.css' });

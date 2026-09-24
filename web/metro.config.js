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
  ['db/provider.web.tsx', 'db/provider.tsx'],
  ['db/provider.tsx', 'db/provider.tsx'],
  ['db/auth.ts', 'db/auth.ts'],
  ['db/connector.ts', 'db/connector.ts'],
  ['app/_layout.tsx', 'components/RootLayout.tsx'],
  ['components/community/MediaGallery.tsx', 'components/MediaGallery.tsx'],
].map(([source, target]) => [path.join(shared, source), path.resolve(__dirname, target)]));
config.resolver.resolveRequest = (context, moduleName, platform) => {
  if (moduleName === '@powersync/react-native') moduleName = '@powersync/common';
  if (moduleName.startsWith('@/')) moduleName = path.join(shared, moduleName.slice(2));
  const resolved = context.resolveRequest(context, moduleName, platform);
  if (resolved.type === 'sourceFile' && overrides.has(resolved.filePath)) {
    return { ...resolved, filePath: overrides.get(resolved.filePath) };
  }
  return resolved;
};
module.exports = withNativeWind(config, { input: './global.css' });

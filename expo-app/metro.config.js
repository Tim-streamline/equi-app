const { getSentryExpoConfig, withSentryBabelTransformer } = require('@sentry/react-native/metro');
const { withNativeWind } = require('nativewind/metro');

const config = getSentryExpoConfig(__dirname);

const { transformer, resolver } = config;
config.transformer = {
  ...transformer,
  babelTransformerPath: require.resolve('react-native-svg-transformer/expo'),
};
config.resolver = {
  ...resolver,
  assetExts: resolver.assetExts.filter((ext) => ext !== 'svg'),
  sourceExts: [...resolver.sourceExts, 'svg'],
};

// App code importing `react-native` gets brand defaults for Text and Pressable;
// everything else is re-exported unchanged. Dependencies are not redirected.
// The defaults module lives alone in its directory: Metro caches resolutions per
// directory, so a neighbour's redirect must never apply to its own import.
const path = require('node:path');
const uiDefaultsDir = path.join(__dirname, 'components/react-native-defaults');
const uiDefaults = path.join(uiDefaultsDir, 'index.tsx');
const upstreamResolve = config.resolver.resolveRequest;
config.resolver.resolveRequest = (context, moduleName, platform) => {
  const origin = context.originModulePath;
  if (moduleName === 'react-native' && origin.startsWith(__dirname + path.sep) && !origin.includes(`${path.sep}node_modules${path.sep}`) && path.dirname(origin) !== uiDefaultsDir) {
    moduleName = uiDefaults;
  }
  return (upstreamResolve ?? context.resolveRequest)(context, moduleName, platform);
};

// Wrap the SVG transformer so both SVG imports and Router error boundaries work.
module.exports = withNativeWind(
  withSentryBabelTransformer(config, false, true),
  { input: './global.css' },
);

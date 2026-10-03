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

// Wrap the SVG transformer so both SVG imports and Router error boundaries work.
module.exports = withNativeWind(
  withSentryBabelTransformer(config, false, true),
  { input: './global.css' },
);

module.exports = ({ config }) => ({
  ...config,
  name: require('./constants/brand.json').name,
  plugins: [
    ...(config.plugins ?? []),
    ['@sentry/react-native/expo', {
      url: 'https://us-west-2a-sourcemaps.betterstackdata.com/',
      organization: '607744',
      project: '2781655',
      // Builds without a private upload token still capture errors.
      disableAutoUpload: !process.env.SENTRY_AUTH_TOKEN,
    }],
  ],
});

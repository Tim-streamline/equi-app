module.exports = ({ config }) => ({
  ...config,
  name: require('../expo-app/constants/brand.json').name,
});

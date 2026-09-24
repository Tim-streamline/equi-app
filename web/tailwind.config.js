const shared = require('../expo-app/tailwind.config');
module.exports = { ...shared, darkMode: 'class', content: ['./components/**/*.{ts,tsx}', '../expo-app/app/**/*.{ts,tsx}', '../expo-app/components/**/*.{ts,tsx}'] };

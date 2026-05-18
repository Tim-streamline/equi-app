/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/**/*.{js,jsx,ts,tsx}',
    './components/**/*.{js,jsx,ts,tsx}',
  ],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        mint: {
          50: '#EAFBF9',
          100: '#C9F3EE',
          200: '#99E8DF',
          300: '#5FD7CB',
          400: '#30C7BA',
          500: '#18BAB0',
          600: '#14A39A',
          700: '#108A82',
          800: '#0E6F69',
          900: '#0A4F4B',
        },
        teal: {
          50: '#E6F1F0',
          100: '#BFD9D8',
          200: '#8FBCBA',
          300: '#5C9F9D',
          400: '#2E8786',
          500: '#127A79',
          600: '#106E6D',
          700: '#0D5C5B',
          800: '#0B4A49',
          900: '#083635',
        },
        logo: '#0D3B34',
        canvas: '#FBF8F3',
        'canvas-2': '#F4EFE7',
        ink: {
          DEFAULT: '#1B2A2A',
          70: 'rgba(27, 42, 42, 0.7)',
          50: 'rgba(27, 42, 42, 0.5)',
          30: 'rgba(27, 42, 42, 0.3)',
          15: 'rgba(27, 42, 42, 0.15)',
          8: 'rgba(27, 42, 42, 0.08)',
        },
        success: '#2EA875',
        warning: '#D9A441',
        danger: '#C2543E',
      },
      borderRadius: {
        card: '18px',
        pill: '9999px',
      },
      fontFamily: {
        sans: ['SourceSans3_400Regular'],
        medium: ['SourceSans3_500Medium'],
        semi: ['SourceSans3_600SemiBold'],
        bold: ['SourceSans3_700Bold'],
        italic: ['SourceSans3_400Regular_Italic'],
        'semi-italic': ['SourceSans3_600SemiBold_Italic'],
      },
      letterSpacing: {
        display: '0.04em',
        eyebrow: '0.14em',
      },
    },
  },
  plugins: [],
};

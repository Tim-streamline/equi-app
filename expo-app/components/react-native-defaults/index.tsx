/**
 * App-wide defaults for React Native primitives.
 *
 * Metro resolves `react-native` imports from this project's own source to this
 * module (see metro.config.js here and in ../web). Everything is re-exported
 * unchanged except:
 * - Text: regular brand font unless a font is set; no Android font padding,
 *   which pushes Source Sans 3 off-centre in buttons and pills.
 * - Pressable: a pressed state for controls that do not define one.
 * Import directly from this file only in code Metro does not redirect.
 */
import { createContext, useContext, type ComponentProps } from 'react';
import { Platform, Pressable as RNPressable, StyleSheet, Text as RNText } from 'react-native';
import { pressableClassName, textClassName } from '@/lib/ui-defaults';

export * from 'react-native';

const InsideText = createContext(false);
const androidText = Platform.OS === 'android' ? { includeFontPadding: false } : null;

type TextProps = ComponentProps<typeof RNText> & { className?: string };

export function Text({ className, style, ...props }: TextProps) {
  const nested = useContext(InsideText);
  const hasStyleFont = !!StyleSheet.flatten(style)?.fontFamily;
  return <InsideText.Provider value>
    <RNText {...props} className={textClassName(className, hasStyleFont, nested)} style={nested ? style : [androidText, style]} />
  </InsideText.Provider>;
}

type PressableProps = ComponentProps<typeof RNPressable> & { className?: string };

export function Pressable({ className, ...props }: PressableProps) {
  const classes = pressableClassName(className, props);
  // NativeWind's JSX runtime strips `cssInterop={false}` at the call site, so it
  // never reaches this wrapper. Without classes there is nothing to interop, and
  // the plain Pressable keeps style callbacks, which NativeWind drops on native.
  if (!classes) return <RNPressable {...props} cssInterop={false} />;
  return <RNPressable {...props} className={classes} />;
}

/** Brand font classes from tailwind.config.js (`fontFamily`). */
const FONT_CLASS = /(^|\s)font-(sans|medium|semi|bold|italic|semi-italic)(\s|$)/;
const ACTIVE_CLASS = /(^|\s)active:/;

/**
 * Root Text without its own font gets the regular brand font. Nested Text keeps
 * inheriting from its parent (adding a font there would undo a parent's bold).
 */
export function textClassName(className: string | undefined, hasStyleFont: boolean, nested: boolean): string | undefined {
  if (nested || hasStyleFont || FONT_CLASS.test(className ?? '')) return className;
  return className ? `font-sans ${className}` : 'font-sans';
}

/**
 * Pressables that do something get a pressed state unless they define their own
 * `active:` style. Opt out with `active:opacity-100` (e.g. modal backdrops).
 * Style callbacks handle feedback themselves.
 */
export function pressableClassName(className: string | undefined, props: { onPress?: unknown; onLongPress?: unknown; disabled?: boolean | null; style?: unknown }): string | undefined {
  if (!props.onPress && !props.onLongPress) return className;
  if (props.disabled || typeof props.style === 'function' || ACTIVE_CLASS.test(className ?? '')) return className;
  return className ? `${className} active:opacity-70` : 'active:opacity-70';
}

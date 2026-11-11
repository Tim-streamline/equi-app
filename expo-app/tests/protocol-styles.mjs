import { readFileSync } from 'node:fs';
import * as protocolStyles from '../components/protocol/styles.ts';
import * as uiStyles from '../components/ui/styles.ts';

// The real style modules, for tests that load protocol components.
const styles = { '@/components/protocol/styles': protocolStyles, '@/components/ui/styles': uiStyles };

/** For tests that load a component with an explicit module map. */
export const protocolStyleModules = { ...styles, './styles': protocolStyles };

/**
 * For tests that run a slice of protocol.tsx and inject its imports by name:
 * the style identifiers under whatever names protocol.tsx imports them as.
 */
const screen = readFileSync(new URL('../app/(tabs)/(pager)/protocol.tsx', import.meta.url), 'utf8');
export const protocolStyleGlobals = Object.fromEntries(Object.entries(styles).flatMap(([module, exports]) => {
  const names = screen.match(new RegExp(`import\\s*\\{([^}]*)\\}\\s*from\\s*["']${module}["']`))?.[1] ?? '';
  return names.split(',').map(name => name.trim()).filter(Boolean).map(name => {
    const [imported, local = imported] = name.split(/\s+as\s+/);
    return [local, exports[imported]];
  });
}));

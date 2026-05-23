// Web-only: TinyBase's <Inspector /> renders a draggable panel that lists
// every Table, Row, Cell and Value in the store and lets you edit them in
// place. Disabled in production bundles.

import { Inspector } from 'tinybase/ui-react-inspector';

export function DbInspector() {
  if (!__DEV__) return null;
  return <Inspector />;
}

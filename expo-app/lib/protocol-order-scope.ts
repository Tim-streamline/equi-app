import type { DashboardOrderItem } from "@/lib/horse-dashboard";

/**
 * Which part of the protocol an order sheet covers. `all` is the full protocol;
 * otherwise rows are recalculated from the server's per-selection components.
 */
export type OrderScope =
  | "all"
  | { phaseId: string; label?: string }
  | { phaseIds: string[]; label?: string }
  | { selections: { id: string; phaseId?: string }[]; label?: string };

type Component = DashboardOrderItem["components"][number];

/**
 * Splits the order discount at its last ": " so the code can get a line of its own. `code` is null
 * without a separator. Shared with the admin's customer preview.
 */
export function splitDiscount(discount: string | null | undefined) {
  const text = discount ?? "";
  const split = text.lastIndexOf(": ");
  return split < 0 ? { label: text, code: null } : { label: text.slice(0, split + 1), code: text.slice(split + 2) };
}

/** Same presentation as the server's order list: 1000 g → 1 kg, comma decimals. */
export function orderQuantityLabel(quantity: number, unit: string | null) {
  let value = quantity, shown = unit;
  if (value >= 1000 && (unit === "g" || unit === "ml")) {
    value /= 1000;
    shown = unit === "g" ? "kg" : "l";
  }
  const number = value.toFixed(9).replace(/\.?0+$/, "").replace(".", ",");
  return shown ? `${number} ${shown}` : number;
}

// A count of drops means little to customers, who buy a bottle. 20 drops per ml is the usual rule
// of thumb; the sizes are the ones Dtails sells drop bottles in.
const DROPS_PER_ML = 20;
const DROP_BOTTLES_ML = [10, 30, 50, 100];

/** For a quantity in drops: about how many ml that is and the smallest bottle that holds it. */
export function dropBottleAdvice(quantity: number | null | undefined, unit: string | null | undefined) {
  if (unit !== "druppels" || typeof quantity !== "number" || !Number.isFinite(quantity) || quantity <= 0) return null;
  const ml = Math.ceil(quantity / DROPS_PER_ML);
  const bottle = DROP_BOTTLES_ML.find((size) => size >= ml) ?? null;
  return { ml, bottle };
}

/** True when a product in the list is set to more than one gift a day ("Giften per dag" in the admin). */
export function hasExtraGifts(items: DashboardOrderItem[] | undefined) {
  return !!items?.some((item) => Array.isArray(item.components) && item.components.some((component) => (component.administrationsPerDay ?? 1) > 1));
}

/**
 * The order list for a customer who can give only one gift a day: products with more gifts a day
 * count one, so their quantity is divided by their gifts per day. Other products stay as they are.
 */
export function onceDailyOrderItems(items: DashboardOrderItem[] | undefined, onceDaily: boolean): DashboardOrderItem[] | undefined {
  if (!onceDaily || !items) return items;
  return items.map((item) => {
    if (!Array.isArray(item.components) || !item.components.some((component) => (component.administrationsPerDay ?? 1) > 1)) return item;
    const components = item.components.map((component) => {
      const perDay = component.administrationsPerDay ?? 1;
      if (perDay <= 1) return component;
      return {
        ...component,
        quantity: typeof component.quantity === "number" ? Math.round(component.quantity / perDay * 1e9) / 1e9 : component.quantity,
        administrationsPerWeek: component.daysPerWeek,
        administrations: component.administrations === null ? null : Math.round(component.administrations / perDay),
      };
    });
    const complete = components.every((component) => typeof component.quantity === "number" && Number.isFinite(component.quantity));
    const quantity = complete ? Math.round(components.reduce((sum, component) => sum + (component.quantity as number), 0) * 1e9) / 1e9 : null;
    return { ...item, components, quantity, calculationComplete: complete, quantityLabel: quantity === null ? null : orderQuantityLabel(quantity, item.unit) };
  });
}

/**
 * Restrict full-protocol aggregates to the selected phase or notice items. Quantities
 * are summed from the matching components only, so a phase never shows the full total.
 * Rows that cannot be scoped (no component data) keep their name but lose the total.
 */
export function scopeOrderItems(items: DashboardOrderItem[] | undefined, scope: OrderScope | null): DashboardOrderItem[] {
  if (!items || !scope) return [];
  if (scope === "all") return items;
  const matches: (component: Component) => boolean = "phaseId" in scope
    ? (component) => component.phaseId === scope.phaseId
    : "phaseIds" in scope ? (component) => scope.phaseIds.includes(component.phaseId) : (() => {
        const selected = new Map(scope.selections.map((item) => [item.id, item.phaseId]));
        return (component: Component) => selected.has(component.selectionId)
          && (!selected.get(component.selectionId) || selected.get(component.selectionId) === component.phaseId);
      })();
  return items.flatMap((item) => {
    if (!Array.isArray(item.components)) {
      // Older payloads without components: show the product only when it was selected.
      const ids = "selections" in scope ? scope.selections.map((selection) => selection.id) : [];
      const selected = ids.includes(item.id) || item.selectionIds?.some((id) => ids.includes(id));
      return selected ? [{ ...item, quantity: null, quantityLabel: null, calculationComplete: false }] : [];
    }
    const seen = new Set<string>();
    const components = item.components.filter((component) => {
      if (!matches(component) || seen.has(component.selectionId)) return false;
      seen.add(component.selectionId);
      return true;
    });
    if (!components.length) return [];
    const complete = components.every((component) => typeof component.quantity === "number" && Number.isFinite(component.quantity));
    const quantity = complete
      ? Math.round(components.reduce((sum, component) => sum + (component.quantity as number), 0) * 1e9) / 1e9
      : null;
    return [{
      ...item,
      components,
      selectionIds: components.map((component) => component.selectionId),
      quantity,
      calculationComplete: complete,
      quantityLabel: quantity === null ? null : orderQuantityLabel(quantity, item.unit),
    }];
  });
}

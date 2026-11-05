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

/**
 * Tri-state logic of CheckboxGroup (docs/ui.md §7), kept pure for tests.
 */

export interface CheckboxGroupItem {
  key: string;
  checked: boolean;
  /** Always on, or unavailable: never changed by the group checkbox. */
  disabled?: boolean;
}

export type GroupState = "all" | "some" | "none";

/** All on, some on, or none on, over every child. */
export function groupState(items: CheckboxGroupItem[]): GroupState {
  const on = items.filter((item) => item.checked).length;

  if (items.length > 0 && on === items.length) {
    return "all";
  }

  return on === 0 ? "none" : "some";
}

/**
 * What activating the group checkbox does: when every available child is
 * on, it turns them off; otherwise (mixed or none) it turns them all on.
 * Disabled children keep their state. Returns the new state of each key.
 */
export function toggleGroup(
  items: CheckboxGroupItem[],
): Record<string, boolean> {
  const available = items.filter((item) => !item.disabled);
  const turnOn = !available.every((item) => item.checked);

  return Object.fromEntries(
    items.map((item) => [item.key, item.disabled ? item.checked : turnOn]),
  );
}

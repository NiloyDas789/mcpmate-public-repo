import { __, _n, sprintf } from "@wordpress/i18n";
import type { Tool, ToolGroup, ToolKind, ToolsUpdate } from "../../types/api";

/**
 * Pure helpers over `GET /tools` (docs/ui.md §5.3), shared by the Tools page
 * and the Edit access picker on Connections.
 */

/** A group with only the tools an AI app can be given: on site-wide and available. */
export interface PickableGroup {
  group: ToolGroup;
  tools: Tool[];
}

export function isOnSiteWide(group: ToolGroup, tool: Tool): boolean {
  return group.available && tool.enabled;
}

/** Groups that have at least one tool turned on, with only those tools. */
export function pickableGroups(groups: ToolGroup[]): PickableGroup[] {
  return groups
    .map((group) => ({
      group,
      tools: group.tools.filter((tool) => isOnSiteWide(group, tool)),
    }))
    .filter((entry) => entry.tools.length > 0);
}

/** Groups with tools that are off for every AI app, and how many. */
export function offGroups(
  groups: ToolGroup[],
): { group: ToolGroup; off: number; whole: boolean }[] {
  return groups
    .map((group) => {
      const off = group.tools.filter(
        (tool) => !isOnSiteWide(group, tool),
      ).length;

      return { group, off, whole: off === group.tools.length };
    })
    .filter((entry) => entry.off > 0);
}

/** Names of the tools that can never be left out (`check_approval`). */
export function alwaysOnNames(groups: ToolGroup[]): string[] {
  return groups.flatMap((group) =>
    group.tools.filter((tool) => tool.always_on).map((tool) => tool.name),
  );
}

/**
 * "4 read, 2 write" over every tool in the list. Write includes the tools
 * that ask you first.
 */
export function kindCounts(tools: { kind: ToolKind }[]): string {
  const read = tools.filter((tool) => tool.kind === "read").length;
  const write = tools.length - read;
  const parts: string[] = [];

  if (read > 0) {
    parts.push(
      sprintf(
        /* translators: %d: number of read tools. */
        _n("%d read", "%d read", read, "mcpmate"),
        read,
      ),
    );
  }

  if (write > 0) {
    parts.push(
      sprintf(
        /* translators: %d: number of write tools. */
        _n("%d write", "%d write", write, "mcpmate"),
        write,
      ),
    );
  }

  return parts.join(__(", ", "mcpmate"));
}

// ── Rows and sections of the Tools page ─────────

export type ToolSource = ToolGroup["source"];

export type ToolFilter = "all" | "read" | "write";

/**
 * One checkbox: a tool, or one item (CLI command, ability) of a tool that
 * has items. An item counts as on only while its tool is on, as the server
 * counts it.
 */
export interface ToolRow {
  /** `site_info`, or `run_wp_cli:plugin list` for an item. */
  key: string;
  tool: string;
  item?: string;
  label: string;
  /** The mono name: the tool's, or the command's or ability's. */
  name: string;
  description: string;
  kind: ToolKind;
  approval: boolean;
  alwaysOn: boolean;
  checked: boolean;
}

/** What the page shows as one tri-state group. */
export interface ToolSection {
  /** The group's ID, or `abilities:<namespace>`. */
  id: string;
  label: string;
  group: ToolGroup;
  rows: ToolRow[];
  /** The tool behind every row, when there is one (`run_wp_cli`). */
  toolName?: string;
}

const itemKey = (tool: string, item: string) => `${tool}:${item}`;

function rowsOf(tool: Tool): ToolRow[] {
  if (!tool.items) {
    return [
      {
        key: tool.name,
        tool: tool.name,
        label: tool.label,
        name: tool.name,
        description: tool.description,
        kind: tool.kind,
        approval: tool.approval,
        alwaysOn: tool.always_on,
        checked: tool.always_on || tool.enabled,
      },
    ];
  }

  return tool.items.map((item) => ({
    key: itemKey(tool.name, item.name),
    tool: tool.name,
    item: item.name,
    label: item.label,
    name: item.name,
    description: "",
    kind: item.kind,
    approval: item.approval,
    alwaysOn: false,
    checked: tool.enabled && item.enabled,
  }));
}

function singleTool(rows: ToolRow[]): string | undefined {
  const names = new Set(rows.map((row) => row.tool));

  return names.size === 1 ? rows[0].tool : undefined;
}

/**
 * The sections of one tab, in catalog order. Abilities are split by the
 * plugin that registered them (the namespace before `/`), after the
 * group's own tools (`list_abilities`).
 */
export function sectionsFor(
  groups: ToolGroup[],
  source: ToolSource,
): ToolSection[] {
  return groups
    .filter((group) => group.source === source)
    .flatMap((group): ToolSection[] => {
      if (source !== "abilities") {
        const rows = group.tools.flatMap(rowsOf);

        return [
          {
            id: group.id,
            label: group.label,
            group,
            rows,
            toolName: singleTool(rows),
          },
        ];
      }

      const own = group.tools.filter((tool) => !tool.items).flatMap(rowsOf);
      const byNamespace = new Map<string, ToolRow[]>();
      group.tools
        .filter((tool) => tool.items)
        .flatMap(rowsOf)
        .forEach((row) => {
          const namespace = row.name.split("/")[0];
          byNamespace.set(namespace, [
            ...(byNamespace.get(namespace) ?? []),
            row,
          ]);
        });

      return [
        ...(own.length > 0
          ? [
              {
                id: group.id,
                label: group.label,
                group,
                rows: own,
                toolName: singleTool(own),
              },
            ]
          : []),
        ...[...byNamespace].map(([namespace, rows]) => ({
          id: `${group.id}:${namespace}`,
          label: sprintf(
            /* translators: %s: the plugin's ability namespace, e.g. "woocommerce". */
            __("Abilities from %s", "mcpmate"),
            namespace,
          ),
          group,
          rows,
          toolName: singleTool(rows),
        })),
      ];
    });
}

/** Ability items on the site (none: the Abilities tab's empty state). */
export function abilityCount(groups: ToolGroup[]): number {
  return groups
    .filter((group) => group.source === "abilities")
    .flatMap((group) => group.tools)
    .reduce((sum, tool) => sum + (tool.items?.length ?? 0), 0);
}

/** On and total over the rows the site can run: a tab's "24/38". */
export function sourceCounts(
  groups: ToolGroup[],
  source: ToolSource,
): { on: number; total: number } {
  const rows = sectionsFor(groups, source)
    .filter((section) => section.group.available)
    .flatMap((section) => section.rows);

  return { on: rows.filter((row) => row.checked).length, total: rows.length };
}

/** "Write" includes the tools that ask you first. */
export function matchesFilter(row: ToolRow, filter: ToolFilter): boolean {
  return filter === "all" || row.kind === filter;
}

/** Label, tool name, command or ability name, and description. */
export function matchesSearch(row: ToolRow, search: string): boolean {
  const needle = search.trim().toLowerCase();
  if (!needle) {
    return true;
  }

  return [row.label, row.name, row.tool, row.description].some((text) =>
    text.toLowerCase().includes(needle),
  );
}

/**
 * The sections with only the rows that pass the filter and search; a
 * section left with none is dropped. Sections the site cannot run have
 * no rows to show and stay only while nothing narrows the list.
 */
export function visibleSections(
  sections: ToolSection[],
  filter: ToolFilter,
  search: string,
): ToolSection[] {
  const narrowed = filter !== "all" || search.trim() !== "";

  return sections
    .map((section) => ({
      ...section,
      rows: section.rows.filter(
        (row) => matchesFilter(row, filter) && matchesSearch(row, search),
      ),
    }))
    .filter((section) =>
      section.group.available ? section.rows.length > 0 : !narrowed,
    );
}

/** Rows a filter and search leave in a tab (unavailable groups excluded). */
export function matchCount(
  groups: ToolGroup[],
  source: ToolSource,
  filter: ToolFilter,
  search: string,
): number {
  return visibleSections(sectionsFor(groups, source), filter, search)
    .filter((section) => section.group.available)
    .reduce((sum, section) => sum + section.rows.length, 0);
}

// ── Changes ─────────────────────────────────

export function emptyChange(): ToolsUpdate {
  return { tools: {}, items: {}, options: {} };
}

export function isEmptyChange(change: ToolsUpdate): boolean {
  return (
    Object.keys(change.tools).length === 0 &&
    Object.keys(change.items).length === 0 &&
    Object.keys(change.options).length === 0
  );
}

/** `later` wins where both say something. */
export function mergeChanges(
  earlier: ToolsUpdate,
  later: ToolsUpdate,
): ToolsUpdate {
  const items = { ...earlier.items };
  Object.entries(later.items).forEach(([tool, states]) => {
    items[tool] = { ...items[tool], ...states };
  });

  return {
    tools: { ...earlier.tools, ...later.tools },
    items,
    options: { ...earlier.options, ...later.options },
  };
}

/** `change` without anything `newer` decides again (a stale Retry). */
export function withoutChange(
  change: ToolsUpdate,
  newer: ToolsUpdate,
): ToolsUpdate {
  const items: ToolsUpdate["items"] = {};
  Object.entries(change.items).forEach(([tool, states]) => {
    const left = Object.entries(states).filter(
      ([name]) => newer.items[tool]?.[name] === undefined,
    );
    if (left.length > 0) {
      items[tool] = Object.fromEntries(left);
    }
  });

  return {
    tools: Object.fromEntries(
      Object.entries(change.tools).filter(([name]) => !(name in newer.tools)),
    ),
    items,
    options: Object.fromEntries(
      Object.entries(change.options).filter(
        ([name]) => !(name in newer.options),
      ),
    ),
  };
}

/**
 * The POST /tools body that gives the rows in `requested` (row key => on)
 * their new state, with only what changes.
 *
 * A tool with items is on while any of its items is on. Its items are sent
 * against the stored enable list, not the checkboxes: when the tool comes
 * back on, an item still in the stored list but shown off (the tool was
 * off) is sent off, so turning one command on never brings back others.
 */
export function buildChange(
  groups: ToolGroup[],
  requested: Record<string, boolean>,
): ToolsUpdate {
  const change = emptyChange();

  groups.forEach((group) =>
    group.tools.forEach((tool) => {
      if (!tool.items) {
        const wanted = requested[tool.name];
        if (
          wanted !== undefined &&
          !tool.always_on &&
          wanted !== tool.enabled
        ) {
          change.tools[tool.name] = wanted;
        }
        return;
      }

      const items = tool.items;
      if (!items.some((item) => itemKey(tool.name, item.name) in requested)) {
        return;
      }

      const after = items.map(
        (item) =>
          requested[itemKey(tool.name, item.name)] ??
          (tool.enabled && item.enabled),
      );
      const anyOn = after.some(Boolean);
      if (anyOn !== tool.enabled) {
        change.tools[tool.name] = anyOn;
      }

      const states: Record<string, boolean> = {};
      items.forEach((item, index) => {
        if (item.enabled !== after[index]) {
          states[item.name] = after[index];
        }
      });
      if (Object.keys(states).length > 0) {
        change.items[tool.name] = states;
      }
    }),
  );

  return change;
}

/** The catalog as it will be once `change` is saved (optimistic view). */
export function applyChange(
  groups: ToolGroup[],
  change: ToolsUpdate,
): ToolGroup[] {
  return groups.map((group) => ({
    ...group,
    options:
      group.id === "media" && change.options.svg !== undefined
        ? { ...group.options, svg: change.options.svg }
        : group.options,
    tools: group.tools.map((tool) => {
      const items = change.items[tool.name];

      return {
        ...tool,
        enabled: change.tools[tool.name] ?? tool.enabled,
        ...(tool.items && {
          items: items
            ? tool.items.map((item) => ({
                ...item,
                enabled: items[item.name] ?? item.enabled,
              }))
            : tool.items,
        }),
      };
    }),
  }));
}

/** "Upload an image or PDF turned on.", or for a whole group, its new count. */
export function describeChange(
  section: ToolSection,
  requested: Record<string, boolean>,
): string {
  const keys = Object.keys(requested);

  if (keys.length === 1) {
    const row = section.rows.find((candidate) => candidate.key === keys[0]);
    if (row) {
      return sprintf(
        requested[keys[0]]
          ? /* translators: %s: tool label. */
            __("%s turned on.", "mcpmate")
          : /* translators: %s: tool label. */
            __("%s turned off.", "mcpmate"),
        row.label,
      );
    }
  }

  const on = section.rows.filter(
    (row) => requested[row.key] ?? row.checked,
  ).length;

  return sprintf(
    /* translators: 1: group name, 2: tools on, 3: tools in the group. */
    __("%1$s: %2$d of %3$d on.", "mcpmate"),
    section.label,
    on,
    section.rows.length,
  );
}

// ── Hash query ──────────────────────────────

const FILTERS: ToolFilter[] = ["all", "read", "write"];

/** `?show=` as a filter; anything unknown is All. */
export function filterFromParam(value: string | null): ToolFilter {
  return FILTERS.includes(value as ToolFilter) ? (value as ToolFilter) : "all";
}

// ── Remembered per browser ──────────────────

const DESCRIPTIONS_KEY = "mcpmate-tools-descriptions";

export function readShowDescriptions(): boolean {
  try {
    return window.localStorage.getItem(DESCRIPTIONS_KEY) === "1";
  } catch {
    return false;
  }
}

export function storeShowDescriptions(show: boolean): void {
  try {
    window.localStorage.setItem(DESCRIPTIONS_KEY, show ? "1" : "0");
  } catch {
    // Private mode or blocked storage: the choice lasts for this visit.
  }
}

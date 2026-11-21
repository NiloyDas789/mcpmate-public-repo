import { __ } from "@wordpress/i18n";
import type { IntegrationPlugin, ToolGroup } from "../../types/api";
import { visibleSections, type ToolFilter, type ToolSection } from "./logic";

/**
 * Pure helpers for Tools → Integrations as plugin tiles: one tile per
 * plugin MCPMate Pro supports (`options.plugins` of the `integrations`
 * group), each holding the sections of its tools, plus a tile for every
 * other integrations group (Stock photos).
 */

export type TileCategory = IntegrationPlugin["category"] | "services";

export type TileStatus = IntegrationPlugin["status"] | "not_configured";

export interface PluginTile {
  /** The plugin's ID (`cf7`), or a group's (`stock_photos`). */
  id: string;
  label: string;
  status: TileStatus;
  theme: boolean;
  category: TileCategory;
  /** Every section of its tools, labelled for the modal. */
  sections: ToolSection[];
  /** The sections the filter and search leave. */
  shown: ToolSection[];
  /** Rows turned on, of rows the site can run. */
  on: number;
  total: number;
  /** Rows the filter and search leave. */
  matches: number;
}

/** The headings tiles sit under, in this order. */
export const CATEGORIES: TileCategory[] = [
  "forms",
  "seo",
  "fields",
  "store",
  "builders",
  "themes",
  "services",
];

export function categoryLabel(category: TileCategory): string {
  switch (category) {
    case "forms":
      return __("Forms", "mcpmate");
    case "seo":
      return __("SEO", "mcpmate");
    case "fields":
      return __("Custom fields", "mcpmate");
    case "store":
      return __("Store", "mcpmate");
    case "builders":
      return __("Page builders", "mcpmate");
    case "themes":
      return __("Themes", "mcpmate");
    default:
      return __("Services", "mcpmate");
  }
}

/** The plugin discovery tools (`list_integration_tools`): above the tiles, not a plugin. */
export const DISCOVERY_SECTION = "integrations";

/** A group the site cannot run, as a tile status. */
function groupStatus(group: ToolGroup): TileStatus {
  if (group.available) {
    return "active";
  }

  switch (group.unavailable_reason) {
    case "plugin_inactive":
      return "installed";
    case "not_configured":
      return "not_configured";
    default:
      return "missing";
  }
}

/** The plugin a section of the `integrations` group belongs to. */
function pluginOf(section: ToolSection): string | undefined {
  return section.rows.find((row) => row.integration)?.integration;
}

/**
 * One tile per plugin, in the server's order, then a tile for every other
 * group on the tab. A plugin's own tool group (Elementor's page tools)
 * joins its tile ahead of its integration operations.
 *
 * `sections` is `sectionsFor(groups, "integrations")`, unfiltered.
 */
export function pluginTiles(
  groups: ToolGroup[],
  sections: ToolSection[],
  filter: ToolFilter,
  search: string,
): PluginTile[] {
  const plugins =
    groups.find((group) => group.id === "integrations")?.options.plugins ?? [];
  const tiles = new Map<
    string,
    Omit<PluginTile, "shown" | "on" | "total" | "matches">
  >();

  plugins.forEach((plugin) =>
    tiles.set(plugin.id, {
      id: plugin.id,
      label: plugin.label,
      status: plugin.status,
      theme: plugin.theme,
      category: plugin.category,
      sections: [],
    }),
  );

  sections.forEach((section) => {
    if (section.group.id === "integrations") {
      if (section.id === DISCOVERY_SECTION) {
        return;
      }
      const id = pluginOf(section) ?? section.id;
      const tile = tiles.get(id) ?? {
        // An item of a plugin the server did not list (an older MCPMate Pro).
        id,
        label: section.label,
        status: "active" as const,
        theme: false,
        category: "builders" as const,
        sections: [],
      };
      tile.sections.push(section);
      tiles.set(id, tile);
      return;
    }

    // A group of its own: a plugin's tools (Elementor) or a service (Stock photos).
    const tile = tiles.get(section.group.id);
    if (tile) {
      tile.sections.unshift(section);
      if (!section.group.available) {
        tile.status = groupStatus(section.group);
      }
      return;
    }
    tiles.set(section.group.id, {
      id: section.group.id,
      label: section.group.label,
      status: groupStatus(section.group),
      theme: false,
      category: "services",
      sections: [section],
    });
  });

  return [...tiles.values()].map((tile) => {
    const labelled = tile.sections.map((section) => ({
      ...section,
      label:
        tile.sections.length === 1
          ? tile.label
          : section.group.id === "integrations"
            ? __("Plugin integration operations", "mcpmate")
            : section.label,
    }));
    const runnable = labelled
      .filter((section) => section.group.available)
      .flatMap((section) => section.rows);
    const shown = visibleSections(labelled, filter, search);

    return {
      ...tile,
      sections: labelled,
      shown,
      on: runnable.filter((row) => row.checked).length,
      total: runnable.length,
      matches: shown
        .filter((section) => section.group.available)
        .reduce((sum, section) => sum + section.rows.length, 0),
    };
  });
}

/**
 * The tiles to show, by heading: every tile while nothing narrows the
 * list, else only tiles with a matching tool.
 */
export function tilesByCategory(
  tiles: PluginTile[],
  narrowed: boolean,
): { category: TileCategory; tiles: PluginTile[] }[] {
  const shown = narrowed ? tiles.filter((tile) => tile.matches > 0) : tiles;

  return CATEGORIES.map((category) => ({
    category,
    tiles: shown.filter((tile) => tile.category === category),
  })).filter((entry) => entry.tiles.length > 0);
}

/** The open tile, from `?plugin=`; unknown IDs open nothing. */
export function tileFromParam(
  tiles: PluginTile[],
  value: string | null,
): PluginTile | null {
  return value ? (tiles.find((tile) => tile.id === value) ?? null) : null;
}

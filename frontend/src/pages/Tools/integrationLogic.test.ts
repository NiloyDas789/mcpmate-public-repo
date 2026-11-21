import { describe, expect, it } from "vitest";

import { toolsFixture } from "../../test/fakeSite";
import type { ToolGroup } from "../../types/api";
import { sectionsFor } from "./logic";
import {
  pluginTiles,
  tileFromParam,
  tilesByCategory,
  type PluginTile,
} from "./integrationLogic";

const groups = (): ToolGroup[] => toolsFixture().groups;

const tiles = (
  view = groups(),
  filter: "all" | "read" | "write" = "all",
  search = "",
) => pluginTiles(view, sectionsFor(view, "integrations"), filter, search);

const byId = (list: PluginTile[], id: string) =>
  list.find((tile) => tile.id === id) as PluginTile;

describe("Tools → Integrations tiles", () => {
  it("gives every supported plugin a tile, installed or not, then the services", () => {
    expect(tiles().map((tile) => [tile.id, tile.status])).toEqual([
      ["cf7", "active"],
      ["wpforms", "missing"],
      ["yoast", "installed"],
      ["acf", "missing"],
      ["woocommerce", "missing"],
      ["elementor", "missing"],
      ["divi", "missing"],
      ["generatepress", "missing"],
      ["stock_photos", "not_configured"],
    ]);
    expect(byId(tiles(), "stock_photos").category).toBe("services");
    expect(byId(tiles(), "divi").theme).toBe(true);
    expect(byId(tiles(), "generatepress").category).toBe("themes");
  });

  it("puts a plugin's operations in its tile, named after the plugin", () => {
    const cf7 = byId(tiles(), "cf7");

    expect(cf7.sections.map((section) => section.label)).toEqual([
      "Contact Form 7",
    ]);
    expect(cf7.sections[0].rows.map((row) => row.name)).toEqual([
      "cf7.check_setup",
      "cf7.list_forms",
      "cf7.update_form_fields",
      "cf7.update_notifications",
    ]);
    expect([cf7.on, cf7.total, cf7.matches]).toEqual([2, 4, 4]);
    // The section's "(plugin not active)" suffix gives way to the tile's status.
    expect(byId(tiles(), "yoast").sections[0].label).toBe("Yoast SEO");
    // A plugin not installed has no operations to show.
    expect(byId(tiles(), "wpforms").sections).toEqual([]);
  });

  it("matches an operation to its plugin by name when the item has no ID", () => {
    const view = groups().map((group) => ({
      ...group,
      tools: group.tools.map((tool) => ({
        ...tool,
        items: tool.items?.map(({ integration: _, ...item }) => item),
      })),
    }));

    expect(byId(tiles(view), "cf7").total).toBe(4);
  });

  it("joins a plugin's own tool group to its tile, ahead of its operations", () => {
    // As an active Elementor sends it: the plugin entry, its own group and its operations.
    const view = groups().map((group) =>
      group.id === "elementor"
        ? { ...group, available: true, unavailable_reason: "" as const }
        : group.id === "integrations"
          ? {
              ...group,
              tools: group.tools.map((tool) =>
                tool.items
                  ? {
                      ...tool,
                      items: [
                        ...tool.items,
                        {
                          name: "elementor.list_elements",
                          label: "List atomic elements",
                          kind: "read" as const,
                          approval: false,
                          enabled: false,
                          section: "Elementor",
                          integration: "elementor",
                        },
                      ],
                    }
                  : tool,
              ),
              options: {
                plugins: group.options.plugins?.map((plugin) =>
                  plugin.id === "elementor"
                    ? { ...plugin, status: "active" as const }
                    : plugin,
                ),
              },
            }
          : group,
    );
    const elementor = byId(tiles(view), "elementor");

    expect(elementor.status).toBe("active");
    expect(elementor.sections.map((section) => section.label)).toEqual([
      "Elementor",
      "Plugin integration operations",
    ]);
    // Neither replaces the other: the page tools and the operations both stay.
    expect(
      elementor.sections.flatMap((s) => s.rows.map((row) => row.name)),
    ).toEqual([
      "elementor_get",
      "elementor_widgets",
      "elementor_save",
      "elementor.list_elements",
    ]);
    expect(elementor.total).toBe(4);
    // A single tile: no second Elementor tile from the group.
    expect(
      tiles(view).filter((tile) => tile.label === "Elementor"),
    ).toHaveLength(1);
  });

  it("counts matches under a filter or search, and hides tiles without one", () => {
    const narrowed = tiles(groups(), "write", "");

    expect(byId(narrowed, "cf7").matches).toBe(2);
    expect(byId(narrowed, "yoast").matches).toBe(0);
    expect(
      tilesByCategory(narrowed, true).flatMap((entry) =>
        entry.tiles.map((tile) => tile.id),
      ),
    ).toEqual(["cf7"]);
    // Nothing narrows the list: every tile, by heading.
    expect(
      tilesByCategory(tiles(), false).map((entry) => entry.category),
    ).toEqual([
      "forms",
      "seo",
      "fields",
      "store",
      "builders",
      "themes",
      "services",
    ]);
  });

  it("opens only a tile that exists", () => {
    expect(tileFromParam(tiles(), "cf7")?.label).toBe("Contact Form 7");
    expect(tileFromParam(tiles(), "nope")).toBeNull();
    expect(tileFromParam(tiles(), null)).toBeNull();
  });
});

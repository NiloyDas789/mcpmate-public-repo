import { describe, expect, it } from "vitest";

import { toolsFixture } from "../../test/fakeSite";
import type { ToolGroup } from "../../types/api";
import {
  applyChange,
  buildChange,
  describeChange,
  filterFromParam,
  isEmptyChange,
  ledgerSelection,
  matchCount,
  mergeChanges,
  sectionsFor,
  sourceCounts,
  visibleSections,
  withoutChange,
  type ToolFilter,
} from "./logic";

const groups = () => toolsFixture().groups;

/** The fixture with `run_wp_cli` off but "post list" still in its stored enable list. */
function cliOffWithStoredItems(): ToolGroup[] {
  return applyChange(groups(), {
    tools: { run_wp_cli: false },
    items: { run_wp_cli: { "post list": true } },
    options: {},
  });
}

describe("Tools logic", () => {
  it("shows a tool with items as one row per item, on only while the tool is on", () => {
    const cli = sectionsFor(groups(), "core").find(
      (section) => section.id === "wp_cli",
    );

    expect(cli?.toolName).toBe("run_wp_cli");
    expect(cli?.rows.map((row) => [row.key, row.checked])).toEqual([
      ["run_wp_cli:core version", true],
      ["run_wp_cli:plugin list", true],
      ["run_wp_cli:post list", false],
      ["run_wp_cli:cache flush", true],
      ["run_wp_cli:option update", false],
      ["run_wp_cli:search-replace", false],
    ]);

    const off = sectionsFor(cliOffWithStoredItems(), "core").find(
      (section) => section.id === "wp_cli",
    );
    expect(off?.rows.every((row) => !row.checked)).toBe(true);
  });

  it("splits integration operations by their plugin, after the group's own tools", () => {
    const integrations: ToolGroup = {
      id: "integrations",
      label: "Plugin integrations",
      source: "integrations",
      available: true,
      unavailable_reason: "",
      options: {},
      tools: [
        {
          name: "list_integration_tools",
          label: "List plugin integration tools",
          description: "",
          kind: "read",
          approval: false,
          always_on: false,
          enabled: true,
          allowed: true,
        },
        {
          name: "run_integration_tool",
          label: "Run a plugin integration tool",
          description: "",
          kind: "write",
          approval: false,
          always_on: false,
          enabled: true,
          allowed: true,
          items: [
            {
              name: "cf7.check_setup",
              label: "Check setup",
              kind: "read",
              approval: false,
              enabled: true,
              section: "Contact Form 7",
              description: "Changes nothing.",
            },
            {
              name: "cf7.get_form",
              label: "Read a form",
              kind: "read",
              approval: false,
              enabled: false,
              section: "Contact Form 7",
            },
            {
              name: "seo.update_meta",
              label: "Update meta",
              kind: "write",
              approval: true,
              enabled: true,
              section: "SEO (plugin not active)",
            },
          ],
        },
      ],
    };

    const sections = sectionsFor([integrations], "integrations");

    expect(
      sections.map((section) => [section.id, section.label, section.toolName]),
    ).toEqual([
      ["integrations", "Plugin integrations", "list_integration_tools"],
      ["integrations:Contact Form 7", "Contact Form 7", "run_integration_tool"],
      [
        "integrations:SEO (plugin not active)",
        "SEO (plugin not active)",
        "run_integration_tool",
      ],
    ]);
    expect(
      sections[1].rows.map((row) => [row.key, row.checked, row.description]),
    ).toEqual([
      ["run_integration_tool:cf7.check_setup", true, "Changes nothing."],
      ["run_integration_tool:cf7.get_form", false, ""],
    ]);
    expect(sections[2].rows[0].approval).toBe(true);
  });

  it("lists abilities by source, sorted, after MCPMate's own tool", () => {
    const sections = sectionsFor(groups(), "abilities");

    expect(sections.map((section) => [section.id, section.label])).toEqual([
      ["abilities", "MCPMate"],
      ["abilities:Forms", "Forms"],
      ["abilities:Shop", "Shop"],
    ]);
    expect(sections[2].rows.map((row) => row.name)).toEqual([
      "shop/export-orders",
      "shop/delete-drafts",
    ]);
    expect(sections[2].rows[0].description).toBe(
      "Exports the shop's orders as a CSV file.",
    );
  });

  it("falls back to the namespace for an ability without a source", () => {
    const fixture = groups().map((group) => ({
      ...group,
      tools: group.tools.map((tool) =>
        tool.name === "run_ability"
          ? {
              ...tool,
              items: (tool.items ?? []).map(({ section: _, ...item }) => item),
            }
          : tool,
      ),
    }));

    expect(
      sectionsFor(fixture, "abilities").map((section) => section.label),
    ).toEqual(["MCPMate", "forms", "shop"]);
  });

  it("opens the source ?source= names while it is shown, else the first", () => {
    const sections = sectionsFor(groups(), "abilities");

    expect(ledgerSelection(sections, "abilities:Shop")?.label).toBe("Shop");
    expect(ledgerSelection(sections, "abilities:Gone")?.label).toBe("MCPMate");
    expect(ledgerSelection(sections, null)?.label).toBe("MCPMate");
    expect(
      ledgerSelection(
        visibleSections(sections, "read", "forms"),
        "abilities:Shop",
      )?.label,
    ).toBe("Forms");
    expect(ledgerSelection([], "abilities:Shop")).toBeNull();
  });

  it("counts on and total per tab over what the site can run", () => {
    // Elementor is not installed and Stock photos has no key: only the
    // plugin integrations count (list_integration_tools and 6 operations).
    expect(sourceCounts(groups(), "integrations")).toEqual({ on: 3, total: 7 });
    expect(sourceCounts(groups(), "abilities")).toEqual({ on: 2, total: 4 });
    // check_approval counts as on: it is always on.
    expect(sourceCounts(groups(), "core")).toEqual({ on: 13, total: 20 });
  });

  it("filters by kind, Write including tools that ask you first", () => {
    const sections = sectionsFor(groups(), "core");
    const write = visibleSections(sections, "write", "");

    expect(
      write.flatMap((section) => section.rows.map((row) => row.name)),
    ).toContain("publish_draft_theme");
    expect(
      write.flatMap((section) => section.rows).every((r) => r.kind === "write"),
    ).toBe(true);
    // Site overview has no write tool: the whole group is hidden.
    expect(write.map((section) => section.id)).not.toContain("site_overview");
  });

  it("searches labels, tool names, command names and descriptions", () => {
    const core = sectionsFor(groups(), "core");
    const names = (search: string) =>
      visibleSections(core, "all", search).flatMap((section) =>
        section.rows.map((row) => row.name),
      );

    expect(names("audit")).toEqual(["get_audit_log"]);
    expect(names("search-replace")).toEqual(["search-replace"]);
    expect(names("run_wp_cli")).toHaveLength(6);
    expect(names("versions, theme")).toEqual(["site_info"]);
    expect(names("nothing like this")).toEqual([]);
    expect(matchCount(groups(), "abilities", "all", "orders")).toBe(1);
  });

  it("keeps an unavailable group only while nothing narrows the list", () => {
    const integrations = sectionsFor(groups(), "integrations");
    const unavailable = (filter: ToolFilter, search: string) =>
      visibleSections(integrations, filter, search)
        .filter((section) => !section.group.available)
        .map((section) => section.id);

    expect(unavailable("all", "")).toEqual(["elementor", "stock_photos"]);
    expect(unavailable("read", "")).toEqual([]);
    expect(unavailable("all", "elementor")).toEqual([]);
  });

  it("sends only what changes for a plain tool", () => {
    expect(
      buildChange(groups(), { upload_media: true, site_info: true }),
    ).toEqual({ tools: { upload_media: true }, items: {}, options: {} });
  });

  it("never sends an always-on tool", () => {
    expect(
      isEmptyChange(buildChange(groups(), { check_approval: false })),
    ).toBe(true);
  });

  it("turns a tool with items off with its last item, and its stored items with it", () => {
    const change = buildChange(groups(), {
      "run_wp_cli:core version": false,
      "run_wp_cli:plugin list": false,
      "run_wp_cli:cache flush": false,
    });

    expect(change).toEqual({
      tools: { run_wp_cli: false },
      items: {
        run_wp_cli: {
          "core version": false,
          "plugin list": false,
          "cache flush": false,
        },
      },
      options: {},
    });
  });

  it("turning one item on never brings back the others of a stored list", () => {
    const change = buildChange(cliOffWithStoredItems(), {
      "run_wp_cli:option update": true,
    });

    expect(change.tools).toEqual({ run_wp_cli: true });
    // Shown off while the tool was off, so they are sent off.
    expect(change.items.run_wp_cli).toEqual({
      "core version": false,
      "plugin list": false,
      "post list": false,
      "cache flush": false,
      "option update": true,
    });

    const after = sectionsFor(
      applyChange(cliOffWithStoredItems(), change),
      "core",
    )
      .find((section) => section.id === "wp_cli")
      ?.rows.filter((row) => row.checked)
      .map((row) => row.name);
    expect(after).toEqual(["option update"]);
  });

  it("applies a change to the catalog, options included", () => {
    const next = applyChange(groups(), {
      tools: { upload_media: true },
      items: { run_ability: { "shop/export-orders": true } },
      options: { svg: true },
    });
    const media = next.find((group) => group.id === "media");

    expect(media?.options.svg).toBe(true);
    expect(media?.tools[0].enabled).toBe(true);
    expect(sourceCounts(next, "abilities")).toEqual({ on: 3, total: 4 });
  });

  it("merges a failed change with a later one, and drops what a newer choice decides", () => {
    const merged = mergeChanges(
      { tools: { a: true }, items: { run_wp_cli: { x: true } }, options: {} },
      {
        tools: { b: false },
        items: { run_wp_cli: { y: false } },
        options: { svg: true },
      },
    );
    expect(merged).toEqual({
      tools: { a: true, b: false },
      items: { run_wp_cli: { x: true, y: false } },
      options: { svg: true },
    });

    expect(
      withoutChange(merged, {
        tools: { a: false },
        items: { run_wp_cli: { x: false } },
        options: {},
      }),
    ).toEqual({
      tools: { b: false },
      items: { run_wp_cli: { y: false } },
      options: { svg: true },
    });
  });

  it("describes one tool by name and a group by its new count", () => {
    const [overview] = sectionsFor(groups(), "core");

    expect(describeChange(overview, { site_info: false })).toBe(
      "Get a summary of this site turned off.",
    );
    expect(
      describeChange(overview, { site_info: false, get_page_html: false }),
    ).toBe("Site overview: 2 of 4 on.");
  });

  it("reads only known filters from the hash query", () => {
    expect(filterFromParam("write")).toBe("write");
    expect(filterFromParam("delete")).toBe("all");
    expect(filterFromParam(null)).toBe("all");
  });
});

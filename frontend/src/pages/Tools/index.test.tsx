import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import Tools from "./index";

/** A working Storage: Node's own experimental `localStorage` shadows happy-dom's. */
function memoryStorage(): Storage {
  const values = new Map<string, string>();

  return {
    get length() {
      return values.size;
    },
    clear: () => values.clear(),
    getItem: (key) => values.get(key) ?? null,
    key: (index) => [...values.keys()][index] ?? null,
    removeItem: (key) => void values.delete(key),
    setItem: (key, value) => void values.set(key, String(value)),
  };
}

beforeEach(() => {
  Object.defineProperty(window, "localStorage", {
    value: memoryStorage(),
    configurable: true,
  });
});

afterEach(() => {
  SERVER_VARIABLES.access = undefined;
  vi.unstubAllGlobals();
});

function renderTools(
  path = "/tools",
  options: Parameters<typeof renderPage>[1] = {},
) {
  return renderPage(<Tools />, { path, route: "tools/*", ...options });
}

const group = (name: string) => screen.getByRole("group", { name });
const groupBox = (name: string) =>
  within(group(name)).getByRole("checkbox", { name });
/** The visible "20 tools" line (the live region says the same). */
const countLine = () =>
  screen.getByRole("tabpanel").querySelector(".el-tools-count");
const announced = () =>
  screen
    .getAllByRole("status")
    .map((region) => region.textContent ?? "")
    .join(" ");

/** The bodies the page sent to POST /tools. */
function saves(fetchMock: ReturnType<typeof vi.fn>): unknown[] {
  return fetchMock.mock.calls
    .filter(([, init]) => init?.method === "POST")
    .map(([, init]) => JSON.parse(String(init?.body)));
}

describe("Tools", () => {
  it("shows four skeleton groups while the tools load", () => {
    renderTools("/tools", { hang: ["tools"] });

    const panel = screen.getByRole("tabpanel");
    expect(panel.querySelector(".el-tools-list")).toHaveAttribute(
      "aria-busy",
      "true",
    );
    expect(panel.querySelectorAll(".el-skeleton")).toHaveLength(4);
    expect(screen.getByText("Loading…")).toBeInTheDocument();
  });

  it("shows an error with Try again when the tools don't load", async () => {
    const user = userEvent.setup();
    const { site, fetchMock } = renderTools("/tools", { fail: ["tools"] });

    expect(
      await screen.findByText("Couldn't load the tools."),
    ).toBeInTheDocument();

    site.setFail("tools", false);
    await user.click(screen.getByRole("button", { name: "Try again" }));

    expect(
      await screen.findByRole("group", { name: "Site overview" }),
    ).toBeInTheDocument();
    expect(
      calledRoutes(fetchMock).filter((route) => route === "tools"),
    ).toHaveLength(2);
  });

  it("names each source tab with how many tools are on", async () => {
    renderTools();

    expect(
      await screen.findByRole("tab", { name: "Core, 13 of 20 on" }),
    ).toHaveAttribute("aria-selected", "true");
    expect(
      // Plugin integrations only: Elementor is not installed, Stock photos has no key.
      screen.getByRole("tab", { name: "Integrations, 3 of 7 on" }),
    ).toHaveAttribute("aria-selected", "false");
    expect(
      screen.getByRole("tab", { name: "Abilities, 2 of 4 on" }),
    ).toBeInTheDocument();
    expect(countLine()).toHaveTextContent("20 tools");
  });

  it("shows each tool with its name and a Read or Write badge", async () => {
    renderTools();

    const overview = await screen.findByRole("group", {
      name: "Site overview",
    });
    const summary = within(overview)
      .getByRole("checkbox", { name: "Get a summary of this site" })
      .closest("li") as HTMLElement;
    expect(within(summary).getByText("site_info")).toBeInTheDocument();
    expect(within(summary).getByText("Read")).toBeInTheDocument();

    const publish = within(group("Theme drafts"))
      .getByRole("checkbox", { name: "Publish a draft theme" })
      .closest("li") as HTMLElement;
    expect(within(publish).getByText("Write")).toBeInTheDocument();
    expect(within(publish).getByText("Asks you first")).toBeInTheDocument();

    // Always on: checked, disabled, and the reason in visible text.
    const check = within(overview).getByRole("checkbox", {
      name: "Check on an approval request",
    });
    expect(check).toBeChecked();
    expect(check).toBeDisabled();
    expect(
      within(check.closest("li") as HTMLElement).getByText("Always on"),
    ).toBeInTheDocument();

    // A group with counts over every tool in it.
    expect(groupBox("Theme drafts")).toHaveAccessibleDescription(
      "3 of 5 on, 2 read, 3 write",
    );
    // run_wp_cli: one row per command, the tool's name on the group.
    expect(
      within(group("WP-CLI commands")).getByText("run_wp_cli"),
    ).toBeInTheDocument();
  });

  it("turns a mixed group on, then off, with the keyboard, saving and announcing each change", async () => {
    const user = userEvent.setup();
    const { fetchMock, site } = renderTools();

    await screen.findByRole("group", { name: "Theme drafts" });
    const box = groupBox("Theme drafts");
    expect(box).toHaveAttribute("aria-checked", "mixed");
    expect(box).toHaveAccessibleDescription("3 of 5 on, 2 read, 3 write");

    box.focus();
    await user.keyboard(" ");

    await waitFor(() => expect(groupBox("Theme drafts")).toBeChecked());
    expect(groupBox("Theme drafts")).not.toHaveAttribute(
      "aria-checked",
      "mixed",
    );
    await waitFor(() =>
      expect(announced()).toContain("Theme drafts: 5 of 5 on. Saved."),
    );
    expect(
      screen.getByText(
        "Saved. Connected AI apps may need to reconnect to see the change.",
      ),
    ).toBeInTheDocument();
    expect(saves(fetchMock)).toEqual([
      {
        tools: { write_theme_file: true, publish_draft_theme: true },
        items: {},
        options: {},
      },
    ]);
    expect(groupBox("Theme drafts")).toHaveFocus();

    await user.keyboard(" ");
    await waitFor(() =>
      expect(announced()).toContain("Theme drafts: 0 of 5 on. Saved."),
    );
    expect(groupBox("Theme drafts")).not.toBeChecked();
    const drafts = site
      .tools()
      .groups.find((entry) => entry.id === "theme_drafts");
    expect(drafts?.tools.every((tool) => !tool.enabled)).toBe(true);
  });

  it("saves one tool at once and says so", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderTools();

    const upload = await screen.findByRole("checkbox", {
      name: "Upload an image or PDF",
    });
    upload.focus();
    await user.keyboard(" ");

    await waitFor(() =>
      expect(announced()).toContain("Upload an image or PDF turned on. Saved."),
    );
    expect(upload).toBeChecked();
    expect(saves(fetchMock)).toEqual([
      { tools: { upload_media: true }, items: {}, options: {} },
    ]);
    expect(
      await screen.findByRole("tab", { name: "Core, 14 of 20 on" }),
    ).toBeInTheDocument();
  });

  it("turns a CLI command on through the stored enable list", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderTools();

    await screen.findByRole("group", { name: "WP-CLI commands" });
    // 6 commands: the group starts open (8 or fewer).
    await user.click(
      within(group("WP-CLI commands")).getByRole("checkbox", {
        name: "List posts",
      }),
    );

    await waitFor(() => expect(saves(fetchMock)).toHaveLength(1));
    expect(saves(fetchMock)[0]).toEqual({
      tools: {},
      items: { run_wp_cli: { "post list": true } },
      options: {},
    });
  });

  it("shows an error with Retry when a save fails, and takes the change back", async () => {
    const user = userEvent.setup();
    const { site } = renderTools("/tools", { fail: ["POST tools"] });

    const upload = await screen.findByRole("checkbox", {
      name: "Upload an image or PDF",
    });
    await user.click(upload);

    const alert = (
      await screen.findByText("Couldn't save your change.")
    ).closest(".el-notice") as HTMLElement;
    expect(alert).toHaveAttribute("role", "alert");
    expect(upload).not.toBeChecked();

    site.setFail("POST tools", false);
    await user.click(within(alert).getByRole("button", { name: "Retry" }));

    await waitFor(() => expect(upload).toBeChecked());
    expect(screen.queryByText("Couldn't save your change.")).toBeNull();
    expect(screen.getByText(/^Saved\./)).toBeInTheDocument();
    expect(
      site
        .tools()
        .groups.flatMap((entry) => entry.tools)
        .find((tool) => tool.name === "upload_media")?.enabled,
    ).toBe(true);
  });

  it("searches every group, says how many tools match, and points to other tabs", async () => {
    const user = userEvent.setup();
    renderTools();

    await screen.findByRole("group", { name: "Site overview" });
    await user.type(
      screen.getByRole("searchbox", { name: "Search tools" }),
      "draft",
    );

    await waitFor(() =>
      expect(countLine()).toHaveTextContent("5 tools match."),
    );
    expect(announced()).toContain("5 tools match.");
    expect(screen.queryByRole("group", { name: "Site overview" })).toBeNull();
    expect(group("Theme drafts")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "1 more in Abilities" }),
    ).toHaveAttribute("href", "/tools/abilities?q=draft");
    expect(location.current).toBe("/tools?q=draft");

    await user.clear(screen.getByRole("searchbox", { name: "Search tools" }));
    await user.type(
      screen.getByRole("searchbox", { name: "Search tools" }),
      "zzz",
    );
    expect(
      await screen.findByRole("heading", { name: "No tools match “zzz”." }),
    ).toBeInTheDocument();
    expect(announced()).toContain("0 tools match.");

    await user.click(screen.getByRole("button", { name: "Clear search" }));
    await waitFor(() =>
      expect(
        screen.getByRole("searchbox", { name: "Search tools" }),
      ).toHaveFocus(),
    );
    await waitFor(() => expect(countLine()).toHaveTextContent("20 tools"));
  });

  it("filters to Read-only or Write with the arrow keys", async () => {
    const user = userEvent.setup();
    renderTools();

    await screen.findByRole("group", { name: "Site overview" });
    const all = screen.getByRole("radio", { name: "All" });
    all.focus();
    await user.keyboard("{ArrowRight}");

    expect(screen.getByRole("radio", { name: "Read-only" })).toBeChecked();
    await waitFor(() => expect(announced()).toContain("12 tools match."));
    expect(screen.queryByRole("group", { name: "Media" })).toBeNull();
    expect(location.current).toBe("/tools?show=read");

    await user.keyboard("{ArrowRight}");
    expect(screen.getByRole("radio", { name: "Write" })).toBeChecked();
    expect(screen.queryByRole("group", { name: "Site overview" })).toBeNull();
    expect(
      within(group("Theme drafts")).queryByRole("checkbox", {
        name: "Read a draft theme file",
      }),
    ).toBeNull();
  });

  it("shows descriptions on request and remembers the choice", async () => {
    const user = userEvent.setup();
    const first = renderTools();

    const summary = await screen.findByRole("checkbox", {
      name: "Get a summary of this site",
    });
    expect(
      screen.queryByText("Site name, versions, theme, plugins and settings."),
    ).toBeNull();

    await user.click(screen.getByRole("switch", { name: "Show descriptions" }));
    expect(summary).toHaveAccessibleDescription(
      "Site name, versions, theme, plugins and settings.",
    );
    first.unmount();

    renderTools();
    expect(
      await screen.findByText(
        "Site name, versions, theme, plugins and settings.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("switch", { name: "Show descriptions" }),
    ).toBeChecked();
  });

  it("shows a tile per plugin under its heading, installed or not", async () => {
    renderTools("/tools/integrations");

    expect(
      await screen.findByRole("tab", { name: "Integrations, 3 of 7 on" }),
    ).toHaveAttribute("aria-selected", "true");
    const panel = screen.getByRole("tabpanel");
    // The discovery tool stays a plain group above the tiles.
    expect(
      await within(panel).findByRole("group", { name: "Plugin integrations" }),
    ).toBeInTheDocument();

    const forms = within(panel).getByRole("region", { name: "Forms" });
    expect(
      within(forms).getByRole("button", { name: "Contact Form 7, 2 of 4 on" }),
    ).toHaveAttribute("aria-haspopup", "dialog");
    expect(
      within(forms).getByRole("button", { name: "WPForms, Not installed" }),
    ).toBeInTheDocument();
    expect(
      within(panel).getByRole("button", {
        name: "Yoast SEO, Installed, not active",
      }),
    ).toBeInTheDocument();
    expect(
      within(panel).getByRole("button", { name: "Divi, Theme not installed" }),
    ).toBeInTheDocument();
    expect(
      within(panel).getByRole("button", {
        name: "Stock photos, Needs an Unsplash key",
      }),
    ).toBeInTheDocument();
    // Operations live in each plugin's dialog, not on the page.
    expect(
      within(panel).queryByRole("checkbox", { name: "List forms" }),
    ).toBeNull();
    // Someone looking for a plugin's own tools is sent to Abilities.
    expect(
      within(panel).getByRole("link", { name: "Go to Abilities" }),
    ).toHaveAttribute("href", "/tools/abilities");
  });

  it("opens a plugin's tools in a dialog kept in the hash, and saves its switches", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderTools("/tools/integrations");

    const tile = await screen.findByRole("button", {
      name: "Contact Form 7, 2 of 4 on",
    });
    await user.click(tile);

    const dialog = await screen.findByRole("dialog", {
      name: "Contact Form 7",
    });
    expect(location.current).toBe("/tools/integrations?plugin=cf7");
    expect(
      within(dialog).getByText(
        "Choose what AI apps may do with Contact Form 7. Each change is saved at once.",
      ),
    ).toBeInTheDocument();
    // Its group starts open, named after the plugin, without the section's suffix.
    const fields = within(dialog).getByRole("checkbox", {
      name: "Change a form's fields",
    });
    expect(fields).not.toBeChecked();

    await user.click(fields);
    await waitFor(() =>
      expect(saves(fetchMock)).toContainEqual({
        tools: {},
        items: { run_integration_tool: { "cf7.update_form_fields": true } },
        options: {},
      }),
    );
    expect(
      await within(dialog).findByText(/Saved\. Connected AI apps/),
    ).toBeInTheDocument();

    // rc-dialog reads `keyCode`, which user-event leaves out for Escape.
    fireEvent.keyDown(document.activeElement ?? document.body, {
      key: "Escape",
      code: "Escape",
      keyCode: 27,
    });
    // Opened here: Esc goes Back, to the list without the dialog.
    await waitFor(() => expect(location.current).toBe("/tools/integrations"));
  });

  it("says what a plugin needs before its tools can run", async () => {
    const user = userEvent.setup();
    renderTools("/tools/integrations?plugin=yoast");

    const dialog = await screen.findByRole("dialog", { name: "Yoast SEO" });
    expect(
      within(dialog).getByText(
        "Activate Yoast SEO first. Until then, AI apps get an error if they use these tools.",
      ),
    ).toBeInTheDocument();
    expect(
      within(dialog).getByRole("link", { name: "Activate in Plugins" }),
    ).toHaveAttribute("href", expect.stringMatching(/plugins\.php$/));
    // Its switches stay, so they can be set before it is activated.
    expect(
      within(dialog).getByRole("checkbox", { name: "Read a post's SEO" }),
    ).toBeInTheDocument();

    await user.click(within(dialog).getByRole("button", { name: "Done" }));
    // Opened from a link: closing drops the key (the Modal tests cover unmounting).
    expect(location.current).toBe("/tools/integrations");
  });

  it("greys out a plugin that is not installed, with no checkboxes", async () => {
    renderTools("/tools/integrations?plugin=elementor");

    const dialog = await screen.findByRole("dialog", { name: "Elementor" });
    expect(within(dialog).getAllByText("Not installed").length).toBeGreaterThan(
      0,
    );
    expect(
      within(dialog).getByText("Install Elementor to use these tools."),
    ).toBeInTheDocument();
    expect(within(dialog).queryByRole("checkbox")).toBeNull();
  });

  it("shows only the tiles a search matches, and the dialog's matches", async () => {
    const user = userEvent.setup();
    renderTools("/tools/integrations?q=forms");

    const tile = await screen.findByRole("button", {
      name: "Contact Form 7, 1 tool matches",
    });
    expect(screen.queryByRole("button", { name: /^WPForms/ })).toBeNull();

    await user.click(tile);
    const dialog = await screen.findByRole("dialog", {
      name: "Contact Form 7",
    });
    expect(
      within(dialog).getByText(/Showing 1 of 4 tools\./),
    ).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Show all" }));
    await waitFor(() =>
      expect(
        within(dialog).getByRole("checkbox", {
          name: "Change a form's fields",
        }),
      ).toBeInTheDocument(),
    );
    expect(location.current).toBe("/tools/integrations?plugin=cf7");
  });

  it("lists abilities as a ledger of sources, each off until turned on", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderTools("/tools/abilities");

    expect(
      await screen.findByText(/Abilities come from your other plugins\./),
    ).toBeInTheDocument();
    const sources = await screen.findByRole("navigation", {
      name: "Ability sources",
    });
    expect(
      within(sources)
        .getAllByRole("link")
        .map((link) => link.querySelector(".sr-only")?.textContent),
    ).toEqual(["MCPMate, 1 of 1 on", "Forms, 1 of 1 on", "Shop, 0 of 2 on"]);
    // The first source is open until one is chosen.
    expect(
      within(sources).getByRole("link", { name: /^MCPMate/ }),
    ).toHaveAttribute("aria-current", "true");
    expect(
      screen.getByRole("heading", { level: 2, name: "MCPMate" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("checkbox", { name: "List abilities" }),
    ).toBeChecked();

    await user.click(within(sources).getByRole("link", { name: /^Shop/ }));
    await waitFor(() =>
      expect(location.current).toBe("/tools/abilities?source=abilities%3AShop"),
    );
    expect(
      screen.getByRole("heading", { level: 2, name: "Shop" })
        .nextElementSibling,
    ).toHaveTextContent("0 of 2 on 2 write");
    expect(
      screen.getByRole("checkbox", { name: "Export orders" }),
    ).not.toBeChecked();
    // Ability IDs are never shown.
    expect(screen.queryByText("shop/export-orders")).not.toBeInTheDocument();
    expect(screen.queryByText("run_ability")).not.toBeInTheDocument();

    await user.click(screen.getByRole("checkbox", { name: "All on" }));
    await waitFor(() => expect(saves(fetchMock)).toHaveLength(1));
    expect(saves(fetchMock)[0]).toEqual({
      tools: {},
      items: {
        run_ability: { "shop/export-orders": true, "shop/delete-drafts": true },
      },
      options: {},
    });
    await waitFor(() =>
      expect(screen.getByRole("checkbox", { name: "All on" })).toBeChecked(),
    );

    // One row off.
    await user.click(
      screen.getByRole("checkbox", { name: "Delete old drafts" }),
    );
    await waitFor(() => expect(saves(fetchMock)).toHaveLength(2));
    expect(saves(fetchMock)[1]).toEqual({
      tools: {},
      items: { run_ability: { "shop/delete-drafts": false } },
      options: {},
    });

    // Searching narrows the sources and keeps the tab.
    await user.type(
      screen.getByRole("searchbox", { name: "Search tools" }),
      "forms",
    );
    await waitFor(() =>
      expect(location.current).toBe(
        "/tools/abilities?source=abilities%3AShop&q=forms",
      ),
    );
    await waitFor(() =>
      expect(countLine()).toHaveTextContent("1 tool matches."),
    );
    expect(
      within(sources)
        .getAllByRole("link")
        .map((link) => link.querySelector(".sr-only")?.textContent),
    ).toEqual(["Forms, 1 matching"]);
    // Shop has no match, so the first source that has one is open.
    expect(
      screen.getByRole("heading", { level: 2, name: "Forms" }),
    ).toBeInTheDocument();
  });

  it("shows each ability's own description when descriptions are on", async () => {
    const user = userEvent.setup();
    renderTools("/tools/abilities?source=abilities%3AShop");

    await screen.findByRole("heading", { level: 2, name: "Shop" });
    expect(
      screen.queryByText("Exports the shop's orders as a CSV file."),
    ).not.toBeInTheDocument();

    await user.click(screen.getByRole("switch", { name: "Show descriptions" }));
    expect(
      screen.getByText("Exports the shop's orders as a CSV file."),
    ).toBeInTheDocument();
    expect(screen.getAllByText("Asks you first")).toHaveLength(1);
  });

  it("picks the source from a select in a narrow container", async () => {
    const user = userEvent.setup();
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({
      width: 600,
      height: 600,
      top: 0,
      left: 0,
      right: 600,
      bottom: 600,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    } as DOMRect);
    renderTools("/tools/abilities");

    const select = await screen.findByRole("combobox", { name: "Source" });
    expect(
      screen.queryByRole("navigation", { name: "Ability sources" }),
    ).not.toBeInTheDocument();
    await user.selectOptions(select, "abilities:Forms");
    await waitFor(() =>
      expect(location.current).toBe(
        "/tools/abilities?source=abilities%3AForms",
      ),
    );
    expect(
      screen.getByRole("heading", { level: 2, name: "Forms" }),
    ).toBeInTheDocument();
    vi.restoreAllMocks();
  });

  it("has no axe violations in the tab panel", async () => {
    renderTools();
    await screen.findByRole("group", { name: "Theme drafts" });

    const result = await axe.run(screen.getByRole("tabpanel"), {
      rules: { "color-contrast": { enabled: false } },
    });
    expect(
      result.violations.map(
        (violation) =>
          `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`,
      ),
    ).toEqual([]);
  }, 60_000);
});

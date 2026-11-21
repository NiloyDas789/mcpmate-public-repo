import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import { makeGrant, toolsFixture } from "../../test/fakeSite";
import { pickableGroups } from "@pages/Tools/logic";
import Connections from "./index";
import {
  accessLabel,
  actsAs,
  initialSelection,
  newGrants,
  sortGrants,
} from "./logic";

const defaultConnection = { ...SERVER_VARIABLES.connection };

afterEach(() => {
  SERVER_VARIABLES.connection = { ...defaultConnection };
  SERVER_VARIABLES.access = undefined;
  vi.unstubAllGlobals();
  window.sessionStorage.clear();
});

function renderConnections(options: Parameters<typeof renderPage>[1] = {}) {
  return renderPage(<Connections />, {
    path: "/connections",
    route: "connections",
    ...options,
  });
}

const appsCard = () => screen.getByRole("region", { name: "AI apps" });
const siteCard = () => screen.getByRole("region", { name: "Your MCP URL" });

/** The data rows of the AI apps table (each has a row header). */
async function appRows() {
  let rows: HTMLElement[] = [];
  await waitFor(() => {
    rows = within(within(appsCard()).getByRole("table"))
      .getAllByRole("row")
      .filter((row) => within(row).queryByRole("rowheader"));
    expect(rows.length).toBeGreaterThan(0);
  });

  return rows;
}

describe("Connections", () => {
  it("shows skeletons while the connection and the apps load", () => {
    renderConnections({ hang: ["connection/status", "grants/list"] });

    expect(siteCard()).toHaveAttribute("aria-busy", "true");
    expect(appsCard()).toHaveAttribute("aria-busy", "true");
  });

  it("shows an error with Try again when nothing loads", async () => {
    renderConnections({ scenario: "errors" });

    expect(
      await screen.findByText("Couldn't load this site's MCP URL."),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Couldn't load your AI apps."),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Couldn't load the registered clients."),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Couldn't load these settings."),
    ).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "Try again" })).toHaveLength(
      4,
    );
  });

  it("with no apps, explains the next step and disables Revoke all with a reason", async () => {
    renderConnections({ scenario: "none" });

    expect(
      await within(appsCard()).findByText("No AI apps connected yet."),
    ).toBeInTheDocument();
    expect(within(appsCard()).getByText("0 of 25 apps")).toBeInTheDocument();
    expect(screen.getByText("No AI apps to revoke.")).toBeInTheDocument();
    expect(
      within(appsCard()).getByRole("button", { name: "Revoke all apps" }),
    ).toBeDisabled();
    expect(
      within(appsCard()).getAllByRole("button", { name: "Connect an AI app" }),
    ).toHaveLength(2);
  });

  it("lists one app with its host, user, access and last use", async () => {
    renderConnections({ scenario: "one" });

    const [row] = await appRows();
    expect(row).toHaveTextContent("Claude");
    expect(row).toHaveTextContent("claude.ai");
    expect(row).toHaveTextContent("acts as admin");
    expect(row).toHaveTextContent("Full access");
    expect(row).toHaveTextContent(/5 min\.? ago/);
    expect(within(appsCard()).getByText("1 of 25 apps")).toBeInTheDocument();
    expect(
      within(row).getByRole("button", { name: "Edit access for Claude" }),
    ).toBeInTheDocument();
    expect(
      within(appsCard()).getByRole("button", { name: "Revoke 1 app" }),
    ).toBeEnabled();

    expect(within(siteCard()).getByLabelText("MCP URL")).toHaveValue(
      "https://example.com/wp-json/mcpmate/mcp",
    );
  });

  it("lists 12 apps, sorts them, and keeps revoked ones behind a disclosure", async () => {
    const user = userEvent.setup();
    renderConnections({ scenario: "many" });

    const rows = await appRows();
    expect(rows).toHaveLength(12);
    expect(within(appsCard()).getByText("12 of 25 apps")).toBeInTheDocument();

    // Last used, newest first: the pending app (never used) is last.
    expect(rows[0]).toHaveTextContent("Claude");
    expect(rows[11]).toHaveTextContent("Cursor");
    expect(rows[11]).toHaveTextContent("Waiting for first use");
    expect(rows[11]).toHaveTextContent("not verified");
    expect(rows[11]).toHaveTextContent("Custom, 4 tools");
    expect(
      rows.find((row) => row.textContent?.includes("ChatGPT")),
    ).toHaveTextContent("Read-only");
    expect(
      rows.find((row) => row.textContent?.includes("Windsurf")),
    ).toHaveTextContent("acts as deleted user #12");

    const lastUsed = screen.getByRole("columnheader", { name: /Last used/ });
    expect(lastUsed).toHaveAttribute("aria-sort", "descending");

    await user.click(
      screen.getByRole("button", { name: /^Approved, not sorted/ }),
    );
    expect(
      screen.getByRole("columnheader", { name: /Approved/ }),
    ).toHaveAttribute("aria-sort", "descending");
    expect((await appRows())[0]).toHaveTextContent("Cursor");

    const disclosure = screen.getByRole("button", {
      name: "Revoked in the last 30 days (2)",
    });
    expect(disclosure).toHaveAttribute("aria-expanded", "false");
    await user.click(disclosure);
    expect(disclosure).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("Old Claude")).toBeVisible();
  });

  it("revokes one app with the keyboard and moves focus to the table", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderConnections({ scenario: "many" });

    const [first] = await appRows();
    within(first)
      .getByRole("button", { name: "Revoke access for Claude" })
      .focus();
    await user.keyboard("{Enter}");

    const dialog = await screen.findByRole("alertdialog", {
      name: "Revoke access for Claude?",
    });
    expect(dialog).toHaveTextContent(
      "Claude (acting as admin) loses access to this site right away.",
    );
    const keep = within(dialog).getByRole("button", { name: "Keep access" });
    await waitFor(() => expect(keep).toHaveFocus());

    await user.keyboard("{Shift>}{Tab}{/Shift}{Enter}");

    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(
        "Claude can no longer use this site.",
      ),
    );
    expect(calledRoutes(fetchMock)).toContain("grants/revoke");
    expect(await appRows()).toHaveLength(11);
    expect(
      screen.getByRole("button", { name: "Revoked in the last 30 days (3)" }),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.getByText(/^AI apps that can use this site/)).toHaveFocus(),
    );
  });

  it("revokes every app after a confirmation that names the count", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderConnections({ scenario: "many" });

    await appRows();
    await user.click(
      within(appsCard()).getByRole("button", { name: "Revoke all 12 apps" }),
    );
    const dialog = await screen.findByRole("alertdialog", {
      name: "Revoke access for all AI apps?",
    });
    expect(dialog).toHaveTextContent(
      "All 12 AI apps lose access to this site right away.",
    );
    // Pausing is offered before the owner revokes everything.
    expect(dialog).toHaveTextContent(
      "Only want to stop them for a while? Keep access, then pause AI access on the Dashboard.",
    );
    expect(
      within(appsCard()).getByRole("link", {
        name: "Pause AI access on the Dashboard",
      }),
    ).toHaveAttribute("href", "#/");
    await user.click(
      within(dialog).getByRole("button", { name: "Revoke all 12 apps" }),
    );

    expect(
      await within(appsCard()).findByText("No AI apps connected yet."),
    ).toBeInTheDocument();
    expect(calledRoutes(fetchMock)).toContain("grants/revoke-all");
  });

  it("edits access: Custom picks from the tools that are on site-wide", async () => {
    const user = userEvent.setup();
    const { fetchMock, site } = renderConnections({ scenario: "one" });

    const [row] = await appRows();
    await user.click(
      within(row).getByRole("button", { name: "Edit access for Claude" }),
    );

    const dialog = await screen.findByRole("dialog", {
      name: "Access for Claude",
    });
    const full = within(dialog).getByRole("radio", { name: /Full access/ });
    expect(full).toBeChecked();
    await waitFor(() => expect(full).toHaveFocus());

    await user.click(within(dialog).getByRole("radio", { name: /Custom/ }));

    const overview = await within(dialog).findByRole("group", {
      name: "Site overview",
    });
    expect(
      within(overview).getByRole("checkbox", { name: "Site overview" }),
    ).toBeChecked();
    expect(within(dialog).getByText("Off for all apps:")).toBeInTheDocument();
    expect(within(dialog).getByText("Media")).toBeInTheDocument();
    expect(
      within(dialog).getByText("Theme drafts: 2 tools"),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("Elementor")).toBeInTheDocument();
    expect(
      within(dialog).getByRole("link", { name: "Turn them on in Tools" }),
    ).toHaveAttribute("href", "#/tools");
    // Off site-wide: never offered as a checkbox.
    expect(
      within(dialog).queryByRole("checkbox", {
        name: "Upload an image or PDF",
      }),
    ).toBeNull();

    await user.click(
      within(dialog).getByRole("checkbox", { name: "WP-CLI commands" }),
    );
    await user.click(
      within(dialog).getByRole("button", { name: "Save access" }),
    );

    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(
        "Access for Claude saved.",
      ),
    );
    expect(calledRoutes(fetchMock)).toContain("grants/update");
    const saved = site.grants()[0];
    expect(saved.access).toBe("custom");
    expect(saved.tools).toContain("check_approval");
    expect(saved.tools).not.toContain("run_wp_cli");
    expect(saved.tools).toContain("query_db");
    expect(row).toHaveTextContent(`Custom, ${saved.tools?.length ?? 0} tools`);
  });

  // Seven group clicks in a modal: slow in happy-dom when the whole suite runs.
  it("refuses Custom with no tools chosen, and says why", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderConnections({ scenario: "one" });

    const [row] = await appRows();
    await user.click(
      within(row).getByRole("button", { name: "Edit access for Claude" }),
    );
    const dialog = await screen.findByRole("dialog", {
      name: "Access for Claude",
    });
    await user.click(within(dialog).getByRole("radio", { name: /Custom/ }));
    await within(dialog).findByRole("group", { name: "Site overview" });

    // Everything starts chosen (Full): one click on each group clears it.
    for (const name of [
      "Site overview",
      "Blocks",
      "Theme drafts",
      "Database",
      "WP-CLI commands",
      "Plugin integrations",
      "Abilities",
    ]) {
      await user.click(within(dialog).getByRole("checkbox", { name }));
    }

    await user.click(
      within(dialog).getByRole("button", { name: "Save access" }),
    );
    expect(
      await within(dialog).findByText("Choose at least one tool."),
    ).toBeInTheDocument();
    expect(calledRoutes(fetchMock)).not.toContain("grants/update");
  }, 20_000);

  it("opens Connect an AI app in the hash and waits for the approval", async () => {
    const user = userEvent.setup();
    const { site } = renderConnections({ scenario: "one" });

    await appRows();
    await user.click(
      within(appsCard()).getAllByRole("button", {
        name: "Connect an AI app",
      })[0],
    );

    const dialog = await screen.findByRole("dialog", {
      name: "Connect an AI app",
    });
    expect(location.current).toBe("/connections?connect-app=claude");
    expect(
      await within(dialog).findByText("Waiting for an approval…"),
    ).toBeInTheDocument();

    const tabs = within(dialog).getByRole("tablist", { name: "AI apps" });
    within(tabs).getByRole("tab", { name: "Claude" }).focus();
    await user.keyboard("{ArrowRight}");
    expect(location.current).toBe("/connections?connect-app=chatgpt");
    expect(within(tabs).getByRole("tab", { name: "ChatGPT" })).toHaveAttribute(
      "aria-selected",
      "true",
    );

    // A new app is approved elsewhere: the next poll finds it.
    site.grants().push(makeGrant(3, { grant_id: "g_NEWNEWNEWNEWNEWNEWNEWN" }));
    await waitFor(
      () =>
        expect(
          within(dialog).getByText(/Claude Code is connected/),
        ).toBeInTheDocument(),
      { timeout: 8000 },
    );

    await user.click(within(dialog).getByRole("button", { name: "Done" }));
    await waitFor(() => expect(location.current).toBe("/connections"));
  }, 15000);

  it("shows the MCP URL and the OAuth issuer with no setup step first", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderConnections({ scenario: "none" });

    const card = siteCard();
    expect(await within(card).findByLabelText("MCP URL")).toHaveValue(
      "https://example.com/wp-json/mcpmate/mcp",
    );
    await user.click(
      within(card).getByRole("button", { name: "Show technical details" }),
    );
    expect(within(card).getByText("https://example.com/mcpmate")).toBeVisible();
    expect(calledRoutes(fetchMock)).not.toContain("connection/register");
  });
});

describe("Connections logic", () => {
  it("names the access level and the acting user", () => {
    expect(accessLabel(makeGrant(0))).toBe("Full access");
    expect(accessLabel(makeGrant(0, { access: "read_only" }))).toBe(
      "Read-only",
    );
    expect(
      accessLabel(makeGrant(0, { access: "custom", tools: ["site_info"] })),
    ).toBe("Custom, 1 tool");
    expect(
      actsAs(
        makeGrant(0, { user: { id: 9, login: null, display_name: null } }),
      ),
    ).toBe("acts as deleted user #9");
  });

  it("sorts never-used apps as the oldest", () => {
    const used = makeGrant(0, { last_used_at: "2026-10-26T09:00:00Z" });
    const never = makeGrant(1, { last_used_at: null });

    expect(
      sortGrants([never, used], { key: "last_used", order: "descending" }),
    ).toEqual([used, never]);
    expect(
      sortGrants([used, never], { key: "last_used", order: "ascending" }),
    ).toEqual([never, used]);
  });

  it("finds apps approved since the modal opened by ID, not by clock", () => {
    const old = makeGrant(0);
    const fresh = makeGrant(1);
    const revoked = makeGrant(2, { status: "revoked" });

    expect(newGrants([old, fresh, revoked], new Set([old.grant_id]))).toEqual([
      fresh,
    ]);
  });

  it("starts Custom from what the current level reaches", () => {
    const groups = pickableGroups(toolsFixture().groups);
    const readOnly = initialSelection(
      makeGrant(0, { access: "read_only" }),
      groups,
    );

    expect(readOnly.has("site_info")).toBe(true);
    expect(readOnly.has("create_draft_theme")).toBe(false);
    expect(initialSelection(makeGrant(0), groups).has("run_wp_cli")).toBe(true);
    expect(
      initialSelection(
        makeGrant(0, { access: "custom", tools: ["query_db"] }),
        groups,
      ),
    ).toEqual(new Set(["query_db"]));
  });
});

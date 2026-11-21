import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import Settings from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
});

function renderSettings(
  path = "/settings",
  options: Parameters<typeof renderPage>[1] = {},
) {
  return renderPage(<Settings />, { path, route: "settings", ...options });
}

const section = (name: string) =>
  screen.getByRole("form", { name }) as HTMLFormElement;

const announced = () =>
  screen
    .getAllByRole("status")
    .map((region) => region.textContent ?? "")
    .join(" ");

/** The `changes` each preferences/update request sent. */
function saves(fetchMock: ReturnType<typeof vi.fn>): unknown[] {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData &&
        body.get("action") === "MCPMATE_preferences/update",
    )
    .map((body) => JSON.parse(String(body.get("changes"))));
}

async function loaded() {
  return screen.findByRole("combobox", { name: "Keep the audit log for" });
}

describe("Settings", () => {
  it("shows a skeleton per section while loading", () => {
    renderSettings("/settings", { hang: ["preferences"] });

    expect(
      screen
        .getByRole("heading", { name: "Logging and history" })
        .closest("section"),
    ).toHaveAttribute("aria-busy", "true");
    expect(screen.getAllByText("Loading…").length).toBeGreaterThan(0);
  });

  it("shows an error with Try again", async () => {
    const user = userEvent.setup();
    const { site } = renderSettings("/settings", { fail: ["preferences"] });

    expect(
      await screen.findByText("Couldn't load your settings."),
    ).toBeInTheDocument();
    site.setFail("preferences", false);
    await user.click(screen.getByRole("button", { name: "Try again" }));

    expect(await loaded()).toHaveValue("90");
  });

  it("shows the three sections and what can't be changed", async () => {
    renderSettings();
    await loaded();

    expect(
      screen.getAllByRole("heading", { level: 2 }).map((h) => h.textContent),
    ).toEqual(["Access and safety", "Logging and history", "Advanced"]);
    expect(
      screen.getByRole("combobox", { name: "Who can approve AI apps" }),
    ).toBeDisabled();
    expect(
      screen.getByText("Only administrators, for now."),
    ).toBeInTheDocument();
    expect(screen.getAllByText("Always on")).toHaveLength(2);
    expect(await screen.findByText(/\d+ of \d+ tools on/)).toBeInTheDocument();
    expect(
      within(section("Access and safety")).getByRole("button", {
        name: "Save changes",
      }),
    ).toBeInTheDocument();
    expect(
      within(section("Logging and history")).getByRole("button", {
        name: "Save changes",
      }),
    ).toBeInTheDocument();
    expect(
      within(section("Advanced")).getByRole("button", { name: "Save changes" }),
    ).toBeInTheDocument();
    expect(screen.queryByText("MCPMate server")).toBeNull();
  });

  it("checks values as they change and refuses to save what the server would refuse", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings();
    await loaded();

    await user.selectOptions(
      screen.getByRole("combobox", { name: "Keep the audit log for" }),
      "60",
    );
    const reads = screen.getByRole("combobox", { name: "Keep read calls for" });
    await user.selectOptions(reads, "90");

    const message =
      "Read calls can't be kept longer than the audit log (60 days).";
    expect(screen.getByText(message)).toBeInTheDocument();
    expect(reads).toHaveAttribute("aria-invalid", "true");
    expect(reads).toHaveAccessibleDescription(
      `No longer than the audit log. ${message}`,
    );
    expect(
      within(section("Logging and history")).getByText("2 unsaved changes"),
    ).toBeInTheDocument();

    // Saving moves focus to the first invalid setting, and sends nothing.
    reads.blur();
    await user.click(
      within(section("Logging and history")).getByRole("button", {
        name: "Save changes",
      }),
    );
    expect(
      await screen.findByText("Fix 1 setting to save."),
    ).toBeInTheDocument();
    await waitFor(() => expect(reads).toHaveFocus());
    expect(saves(fetchMock)).toEqual([]);

    // Fixing it clears the error.
    await user.selectOptions(reads, "30");
    expect(screen.queryByText(message)).toBeNull();
    expect(reads).not.toHaveAttribute("aria-invalid");
  });

  it("needs a kind of change while change history is on, and asks before turning it off", async () => {
    const user = userEvent.setup();
    const { fetchMock, site } = renderSettings();
    await loaded();

    await user.click(
      screen.getByRole("checkbox", { name: "Record changes to" }),
    );
    const message =
      "Choose at least one kind of change to record, or turn change history off.";
    expect(screen.getByText(message)).toBeInTheDocument();
    expect(
      screen.getByRole("checkbox", { name: "Record changes to" }),
    ).toHaveAttribute("aria-invalid", "true");

    await user.click(screen.getByRole("switch", { name: "Change history" }));
    const dialog = await screen.findByRole("alertdialog", {
      name: "Turn off change history?",
    });
    expect(dialog).toHaveTextContent(
      "New changes won't be recorded. Snapshots you already have stay until they expire after 30 days.",
    );
    await user.click(
      within(dialog).getByRole("button", { name: "Turn off change history" }),
    );

    expect(screen.queryByText(message)).toBeNull();
    await user.click(
      within(section("Logging and history")).getByRole("button", {
        name: "Save changes",
      }),
    );

    await waitFor(() => expect(announced()).toContain("Saved."));
    expect(saves(fetchMock)).toEqual([
      { change_history: false, change_history_types: [] },
    ]);
    expect(site.preferences().change_history).toBe(false);
    expect(
      within(section("Logging and history")).getByText("Saved."),
    ).toBeInTheDocument();
  });

  it("keeps change history on when the dialog is cancelled", async () => {
    const user = userEvent.setup();
    renderSettings();
    await loaded();

    await user.click(screen.getByRole("switch", { name: "Change history" }));
    await user.click(await screen.findByRole("button", { name: "Keep it on" }));

    expect(
      screen.getByRole("switch", { name: "Change history" }),
    ).toHaveAttribute("aria-checked", "true");
    expect(
      within(section("Logging and history")).getByText("No unsaved changes."),
    ).toBeInTheDocument();
  });

  it("saves one section at a time, sending only what changed", async () => {
    const user = userEvent.setup();
    const { fetchMock, site } = renderSettings();
    await loaded();

    await user.click(
      screen.getByRole("switch", { name: "Also log read calls" }),
    );
    await user.click(
      screen.getByRole("switch", { name: "Remove all data on uninstall" }),
    );
    expect(
      within(section("Advanced")).getByText("1 unsaved change"),
    ).toBeInTheDocument();

    await user.click(
      within(section("Advanced")).getByRole("button", { name: "Save changes" }),
    );

    await waitFor(() =>
      expect(saves(fetchMock)).toEqual([{ delete_data_on_uninstall: false }]),
    );
    expect(site.preferences().log_reads).toBe(true);
    // The other section keeps its edit.
    expect(
      within(section("Logging and history")).getByText("1 unsaved change"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("switch", { name: "Also log read calls" }),
    ).toHaveAttribute("aria-checked", "false");
  });

  it("sums up token lifetimes and links to Connections, where they are set", async () => {
    renderSettings();
    await loaded();

    const access = within(section("Access and safety"));
    expect(
      access.queryByRole("combobox", { name: "Access tokens last" }),
    ).not.toBeInTheDocument();
    expect(
      access.queryByRole("switch", { name: "Let AI apps register themselves" }),
    ).not.toBeInTheDocument();
    expect(
      access.getByText("Tokens 15 minutes, approvals 30 days"),
    ).toBeInTheDocument();
    expect(
      access.getByRole("link", { name: "Open Connections" }),
    ).toHaveAttribute("href", "#/connections?section=sign-in");
  });

  it("says there is nothing to save", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings();
    await loaded();

    await user.click(
      within(section("Advanced")).getByRole("button", { name: "Save changes" }),
    );

    expect(
      within(section("Advanced")).getByText(
        "Nothing to save. Change a setting first.",
      ),
    ).toBeInTheDocument();
    expect(saves(fetchMock)).toEqual([]);
  });

  it("discards a section's changes", async () => {
    const user = userEvent.setup();
    renderSettings();
    await loaded();

    await user.selectOptions(
      screen.getByRole("combobox", { name: "Keep change history for" }),
      "90",
    );
    await user.click(
      within(section("Logging and history")).getByRole("button", {
        name: "Discard",
      }),
    );

    expect(
      screen.getByRole("combobox", { name: "Keep change history for" }),
    ).toHaveValue("30");
    expect(announced()).toContain("Changes discarded.");
  });

  it("shows the server's refusal next to the setting it names, and keeps the input", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings("/settings", {
      settingsErrors: {
        audit_retention_days:
          "Keep the audit log at least as long as read calls (90 days).",
      },
    });
    const audit = await loaded();

    await user.selectOptions(audit, "180");
    await user.click(
      within(section("Logging and history")).getByRole("button", {
        name: "Save changes",
      }),
    );

    expect(
      await within(section("Logging and history")).findByText(
        "Couldn't save. Nothing was changed.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Keep the audit log at least as long as read calls (90 days).",
      ),
    ).toBeInTheDocument();
    expect(audit).toHaveAttribute("aria-invalid", "true");
    expect(audit).toHaveValue("180");
    await waitFor(() => expect(audit).toHaveFocus());
    expect(saves(fetchMock)).toEqual([{ audit_retention_days: 180 }]);
    expect(
      screen
        .getAllByRole("alert")
        .map((region) => region.textContent)
        .join(" "),
    ).toContain("Couldn't save. Nothing was changed.");
  });

  it("shows a window set in code as fixed", async () => {
    renderSettings("/settings", { filtered: { audit_retention_days: 365 } });

    const audit = await loaded();
    expect(audit).toBeDisabled();
    expect(audit).toHaveValue("365");
    expect(
      screen.getByText("Set by code on this site: 365 days."),
    ).toBeInTheDocument();
  });

  it("asks before leaving with unsaved changes; a section link is not leaving", async () => {
    const user = userEvent.setup();
    const { router } = renderSettings();
    await loaded();

    await user.click(
      screen.getByRole("switch", { name: "Also log read calls" }),
    );

    await act(() => router.navigate("/settings?section=advanced"));
    expect(screen.queryByRole("alertdialog")).toBeNull();

    await act(() => router.navigate("/tools"));
    const dialog = await screen.findByRole("alertdialog", {
      name: "Leave without saving?",
    });
    expect(dialog).toHaveTextContent(
      "You changed 1 setting. If you leave now, that change is lost.",
    );
    await waitFor(() =>
      expect(document.activeElement).toHaveTextContent("Stay on this page"),
    );
    await user.click(
      within(dialog).getByRole("button", { name: "Stay on this page" }),
    );
    expect(location.current).toBe("/settings?section=advanced");

    await act(() => router.navigate("/tools"));
    await user.click(
      within(await screen.findByRole("alertdialog")).getByRole("button", {
        name: "Leave without saving",
      }),
    );
    await waitFor(() => expect(location.current).toBe("/tools"));
  });

  it("moves to a section from ?section=", async () => {
    renderSettings("/settings?section=advanced");
    await loaded();

    await waitFor(() =>
      expect(screen.getByRole("heading", { name: "Advanced" })).toHaveFocus(),
    );
  });

  it("runs setup again from the top and opens the wizard", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings();
    await loaded();

    await user.click(screen.getByRole("button", { name: "Run setup again" }));

    await waitFor(() => expect(location.current).toBe("/setup"));
    expect(calledRoutes(fetchMock)).toContain("onboarding/update");
  });

  // The whole page is checked in a real browser (pnpm test:a11y): axe over
  // every section takes minutes in happy-dom.
  it("has no axe violations in the leave dialog, nor in a section with an error", async () => {
    const user = userEvent.setup();
    const { router } = renderSettings();
    await loaded();
    await user.click(
      screen.getByRole("checkbox", { name: "Record changes to" }),
    );
    const logging = section("Logging and history");
    await act(() => router.navigate("/tools"));
    const dialog = await screen.findByRole("alertdialog");

    for (const region of [dialog, logging]) {
      const result = await axe.run(region, {
        rules: { "color-contrast": { enabled: false } },
      });
      expect(
        result.violations.map(
          (violation) =>
            `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`,
        ),
      ).toEqual([]);
    }
  }, 60_000);
});

describe("Before you uninstall", () => {
  it("opens from Advanced and says what goes and what stays", async () => {
    const user = userEvent.setup();
    renderSettings("/settings", { scenario: "many" });

    await user.click(
      await screen.findByRole("button", {
        name: "Review data before deleting",
      }),
    );

    const drawer = await screen.findByRole("dialog", {
      name: "Before you uninstall",
    });
    expect(location.current).toBe(
      "/settings?section=advanced&review=uninstall",
    );
    expect(
      await within(drawer).findByText("wp_mcpmate_audit_log"),
    ).toBeVisible();
    expect(drawer).toHaveTextContent("Deleted with the plugin");
    expect(drawer).toHaveTextContent(
      "2 theme backups (4.3 MB), in uploads/mcpmate/theme-backups",
    );
    expect(drawer).toHaveTextContent("MCPMATE_theme_draft_records");

    await user.click(within(drawer).getByRole("button", { name: "Close" }));
    await waitFor(() =>
      expect(location.current).toBe("/settings?section=advanced"),
    );
  });

  it("downloads the audit log as one JSON file, a page at a time", async () => {
    const user = userEvent.setup();
    const createObjectURL = vi.fn(() => "blob:mcpmate");
    vi.stubGlobal(
      "URL",
      Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }),
    );
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(() => {});

    const { fetchMock } = renderSettings(
      "/settings?section=advanced&review=uninstall",
      { scenario: "many" },
    );
    const drawer = await screen.findByRole("dialog", {
      name: "Before you uninstall",
    });

    await user.click(
      await within(drawer).findByRole("button", { name: "Download audit log" }),
    );

    await waitFor(() => expect(click).toHaveBeenCalledTimes(1));
    expect(calledRoutes(fetchMock)).toContain("uninstall/export");
    expect(createObjectURL).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(announced()).toMatch(/Downloaded [\d,]+ entr/));
    click.mockRestore();
  });

  it("says nothing is deleted while Remove all data is off", async () => {
    renderSettings("/settings?section=advanced&review=uninstall", {
      preferences: { delete_data_on_uninstall: false },
    });

    const drawer = await screen.findByRole("dialog", {
      name: "Before you uninstall",
    });
    expect(
      await within(drawer).findByText("Nothing will be deleted."),
    ).toBeVisible();
    expect(drawer).toHaveTextContent("Would be deleted with it on");
  });
});

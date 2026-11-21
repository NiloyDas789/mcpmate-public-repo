import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, renderPage } from "../../test/renderPage";
import { makeGrant } from "../../test/fakeSite";
import Dashboard from "./index";
import { checksTally, lastUsed, recentCalls } from "./logic";

const defaults = {
  connection: { ...SERVER_VARIABLES.connection },
  onboarding: { ...SERVER_VARIABLES.onboarding },
};

afterEach(() => {
  SERVER_VARIABLES.connection = { ...defaults.connection };
  SERVER_VARIABLES.onboarding = { ...defaults.onboarding };
  SERVER_VARIABLES.access = undefined;
  SERVER_VARIABLES.read_mode = undefined;
  vi.unstubAllGlobals();
});

const card = (name: string) => screen.getByRole("region", { name });

describe("Dashboard", () => {
  it("shows skeletons while the numbers, apps and activity load", () => {
    renderPage(<Dashboard />, {
      hang: ["grants/list", "stats", "audit-log"],
    });

    expect(card("AI access")).toHaveAttribute("aria-busy", "true");
    expect(card("Recent activity")).toHaveAttribute("aria-busy", "true");
    expect(screen.getAllByText("Loading…").length).toBeGreaterThan(0);
  });

  it("shows an error with Try again for each view that failed", async () => {
    renderPage(<Dashboard />, { scenario: "errors" });

    expect(
      await screen.findByText("Couldn't load your AI apps."),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Couldn't load the numbers."),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Couldn't load recent activity."),
    ).toBeInTheDocument();
    expect(
      screen.getAllByRole("button", { name: "Try again" }).length,
    ).toBeGreaterThanOrEqual(3);
  });

  it("with no AI apps, offers to connect one", async () => {
    renderPage(<Dashboard />, { scenario: "none" });

    const access = card("AI access");
    expect(
      await within(access).findByText("No AI apps connected yet."),
    ).toBeInTheDocument();
    expect(
      within(access).getByRole("link", { name: "Connect an AI app" }),
    ).toHaveAttribute("href", "#/connections?connect-app=claude");
    expect(
      within(access).queryByRole("button", { name: "Pause AI access" }),
    ).toBeNull();

    expect(
      await screen.findByText("No calls in the last 24 hours"),
    ).toBeInTheDocument();
    expect(
      await screen.findByText(
        "When an AI app uses this site, its calls show up here.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("No changes by AI apps in the last 7 days."),
    ).toBeInTheDocument();
    expect(screen.getByText("Not checked yet.")).toBeInTheDocument();
  });

  it("shows the apps, the numbers and the last calls", async () => {
    renderPage(<Dashboard />, { scenario: "one" });

    const access = card("AI access");
    expect(await within(access).findByText("1")).toBeInTheDocument();
    expect(within(access).getByText(/Last used/)).toHaveTextContent(
      /by Claude/,
    );
    expect(within(access).getByText("Active")).toBeInTheDocument();

    expect(await screen.findByText("42")).toBeInTheDocument();
    expect(
      screen.getByText("30 reads, 12 changes in the last 24 hours"),
    ).toBeInTheDocument();
    expect(screen.getByText("95%")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "View failed calls" }),
    ).toHaveAttribute("href", "#/activity?result=error");
    expect(screen.getByText("12 of 16")).toBeInTheDocument();
    // "What did the AI change?" leads straight to Change history.
    expect(
      screen.getByText(/In the last 7 days, AI apps made 14 changes/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "View change history" }),
    ).toHaveAttribute("href", "#/activity/changes");

    const recent = card("Recent activity");
    const rows = await within(recent).findAllByRole("listitem");
    expect(rows).toHaveLength(5);
    expect(within(rows[0]).getByRole("link")).toHaveAttribute(
      "href",
      "#/activity?entry=100",
    );
    expect(rows[0]).toHaveTextContent("Succeeded");
    expect(rows[0]).toHaveTextContent("site_info");
    expect(rows[0]).toHaveTextContent("Claude as admin");
    expect(rows[2]).toHaveTextContent("Failed");
    expect(rows[3]).toHaveTextContent("Waiting for approval");

    expect(
      screen.getByRole("region", { name: "Your MCP URL" }),
    ).toHaveTextContent("Paste this into your AI app");
    expect(screen.getByLabelText("MCP URL")).toHaveValue(
      "https://example.com/wp-json/mcpmate/mcp",
    );
  });

  it("pauses AI access with the keyboard, after a confirmation, and resumes it", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderPage(<Dashboard />, { scenario: "one" });

    const pause = await screen.findByRole("button", {
      name: "Pause AI access",
    });
    pause.focus();
    await user.keyboard("{Enter}");

    const dialog = await screen.findByRole("alertdialog", {
      name: "Pause AI access?",
    });
    expect(dialog).toHaveTextContent("Nothing is revoked");
    // Danger dialogs open on Cancel.
    await waitFor(() =>
      expect(
        within(dialog).getByRole("button", { name: "Cancel" }),
      ).toHaveFocus(),
    );

    await user.keyboard("{Shift>}{Tab}{/Shift}");
    expect(
      within(dialog).getByRole("button", { name: "Pause AI access" }),
    ).toHaveFocus();
    await user.keyboard("{Enter}");

    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("AI access paused."),
    );
    expect(calledRoutes(fetchMock)).toContain("access/pause");
    expect(await screen.findByText(/Paused since .* by Niloy/)).toBeVisible();

    const resume = screen.getByRole("button", { name: "Resume AI access" });
    await waitFor(() => expect(resume).toHaveFocus());
    await user.keyboard("{Enter}");

    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(
        "AI access resumed.",
      ),
    );
    expect(calledRoutes(fetchMock)).toContain("access/resume");
    expect(screen.getByText("Active")).toBeInTheDocument();
  });

  it("turns read mode on after a confirmation, and off again", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderPage(<Dashboard />, { scenario: "one" });

    await user.click(
      await screen.findByRole("button", { name: "Turn on read mode" }),
    );

    const dialog = await screen.findByRole("alertdialog", {
      name: "Turn on read mode?",
    });
    expect(dialog).toHaveTextContent("can't be approved");
    await user.click(
      within(dialog).getByRole("button", { name: "Turn on read mode" }),
    );

    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("Read mode is on."),
    );
    expect(calledRoutes(fetchMock)).toContain("read-mode/on");
    expect(
      await screen.findByText(/Read only since .* by Niloy/),
    ).toBeVisible();
    // Pause stays next to it.
    expect(
      screen.getByRole("button", { name: "Pause AI access" }),
    ).toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Turn off read mode" }),
    );
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("Read mode is off."),
    );
    expect(calledRoutes(fetchMock)).toContain("read-mode/off");
    expect(screen.getByText("Active")).toBeInTheDocument();
  });

  it("shows read mode that was on when the page loaded", async () => {
    renderPage(<Dashboard />, { scenario: "one", readMode: true });

    expect(await screen.findByText(/Read only since/)).toBeVisible();
    expect(
      screen.getByRole("button", { name: "Turn off read mode" }),
    ).toBeInTheDocument();
  });

  it("runs the site checks on request and shows them behind a disclosure", async () => {
    const user = userEvent.setup();
    renderPage(<Dashboard />, { scenario: "one" });

    await user.click(screen.getByRole("button", { name: "Run checks" }));

    const checks = card("Site checks");
    expect(await within(checks).findByText("7 of 9 passed")).toBeVisible();
    expect(within(checks).getByText("2 need attention")).toBeVisible();
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("7 of 9 passed"),
    );

    const show = screen.getByRole("button", { name: "Show all checks (10)" });
    expect(show).toHaveAttribute("aria-expanded", "false");
    await user.click(show);
    expect(
      screen.getByRole("button", { name: "Hide all checks (10)" }),
    ).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("AI apps can reach your MCP URL")).toBeVisible();
    expect(screen.getByText("How to fix")).toBeVisible();
    // The fix comes first, then the lines it names, ready to copy.
    expect(screen.getAllByLabelText("Lines")[0]).toHaveValue(
      "RewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]",
    );
  });

  it("offers to finish setup until it is completed", async () => {
    SERVER_VARIABLES.onboarding = { status: "in_progress", step: "safety" };
    renderPage(<Dashboard />, { scenario: "none" });

    expect(screen.getByText("Finish setting up.")).toBeInTheDocument();
    expect(screen.getByText("You're on step 2 of 4.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Resume setup" })).toHaveAttribute(
      "href",
      "#/setup",
    );
  });

  it("shows the site's own MCP URL", () => {
    renderPage(<Dashboard />, { scenario: "none" });

    expect(screen.getByLabelText("MCP URL")).toHaveValue(
      "https://example.com/wp-json/mcpmate/mcp",
    );
  });
});

describe("Dashboard logic", () => {
  it("finds the app used last, ignoring revoked ones", () => {
    const older = makeGrant(0, { last_used_at: "2026-10-26T08:00:00Z" });
    const newer = makeGrant(1, { last_used_at: "2026-10-26T09:00:00Z" });
    const revoked = makeGrant(2, {
      status: "revoked",
      last_used_at: "2026-10-26T10:00:00Z",
    });

    expect(lastUsed([older, newer, revoked])?.grant).toBe(newer);
    expect(lastUsed([makeGrant(3, { last_used_at: null })])).toBeNull();
  });

  it("lists only AI apps' calls that reached a tool", () => {
    const base = {
      created_at: "",
      user_id: 1,
      user_login: "admin",
      request_id: "",
      tool: "",
      action: "",
      target: "",
      args: {},
      result: "ok" as const,
      message: "",
      grant_id: "",
      kind: "read",
      duration_ms: null,
    };
    const items = [
      { ...base, id: 4, auth: "cookie", mcp_tool: "site_info" },
      { ...base, id: 3, auth: "oauth", mcp_tool: "site_info" },
      { ...base, id: 2, auth: "oauth", mcp_tool: "query_db" },
      { ...base, id: 1, auth: "oauth", mcp_tool: "" },
    ];

    expect(recentCalls(items).map((item) => item.id)).toEqual([3, 2]);
  });

  it("counts pass, warn and fail, and lists info and later without counting them", () => {
    const check = (status: "pass" | "warn" | "fail" | "info" | "later") => ({
      id: "https" as const,
      status,
      title: "",
      message: "",
      fix: "",
      details: [],
    });

    expect(
      checksTally([
        check("pass"),
        check("pass"),
        check("warn"),
        check("fail"),
        check("info"),
        check("later"),
      ]),
    ).toEqual({ passed: 2, counted: 4, attention: 2 });
  });
});

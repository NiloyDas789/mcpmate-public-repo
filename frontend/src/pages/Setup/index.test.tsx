import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { Grant, OnboardingStateResponse } from "../../types/api";
import Setup from "./index";

const connection = {
  ...SERVER_VARIABLES.connection,
  mcp_url: "https://example.com/wp-json/mcpmate/mcp",
};

function grant(overrides: Partial<Grant> = {}): Grant {
  return {
    grant_id: "g_AAAAAAAAAAAAAAAAAAAAAA",
    status: "pending",
    client_name: "Claude",
    client_id: "https://claude.ai/oauth",
    vouched_by: "cimd:claude.ai",
    redirect_host: "claude.ai",
    scopes: ["site:read", "site:write"],
    access: "full",
    tools: null,
    user: { id: 1, login: "admin", display_name: "Admin" },
    created_at: "2026-10-26T10:00:05Z",
    last_used_at: null,
    first_call_at: null,
    revoked_at: null,
    revoke_reason: null,
    ...overrides,
  };
}

function state(
  overrides: Partial<OnboardingStateResponse["onboarding"]> = {},
  rest: Partial<OnboardingStateResponse> = {},
): OnboardingStateResponse {
  return {
    onboarding: {
      v: 1,
      status: "in_progress",
      step: "welcome",
      source: "activation",
      started_at: "2026-10-26 10:00:00",
      updated_at: null,
      completed_at: null,
      features_defaulted: false,
      connect_since: null,
      grant_id: "",
      ...overrides,
    },
    connection,
    grant: null,
    first_call: null,
    features: {
      abilities_supported: true,
      abilities_available: 3,
      elementor_available: false,
      can_edit_themes: true,
      pro_features: true,
    },
    ...rest,
  };
}

type Handler = (body: FormData) => unknown;

/** admin-ajax by action; unknown actions answer `{success: true, data: {}}`. */
function mockAjax(handlers: Record<string, Handler>) {
  const fetchMock = vi
    .fn()
    .mockImplementation(async (_url: string, init: RequestInit) => {
      const body = init.body as FormData;
      const action = String(body.get("action")).replace("MCPMATE_", "");
      const handler = handlers[action];
      const data = handler ? handler(body) : {};

      return new Response(JSON.stringify({ success: true, data }), {
        status: 200,
        headers: { "content-type": "application/json" },
      });
    });
  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

function actions(fetchMock: ReturnType<typeof mockAjax>): FormData[] {
  return fetchMock.mock.calls.map((call) => call[1].body as FormData);
}

function renderSetup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={["/setup"]}>
        <Routes>
          <Route path="/setup" element={<Setup />} />
          <Route path="/" element={<p>Dashboard page</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/** A BroadcastChannel that delivers within the test process. */
class FakeChannel {
  static channels: FakeChannel[] = [];
  onmessage: ((event: MessageEvent) => void) | null = null;
  constructor(public name: string) {
    FakeChannel.channels.push(this);
  }
  postMessage(data: unknown) {
    FakeChannel.channels
      .filter((channel) => channel !== this && channel.name === this.name)
      .forEach((channel) => channel.onmessage?.({ data } as MessageEvent));
  }
  close() {
    FakeChannel.channels = FakeChannel.channels.filter((c) => c !== this);
  }
}

beforeEach(() => {
  FakeChannel.channels = [];
  vi.stubGlobal("BroadcastChannel", FakeChannel);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe("Setup wizard", () => {
  it("starts at Welcome, and Get started saves the step and focuses the next heading", async () => {
    const fetchMock = mockAjax({
      "onboarding/state": () => state({ status: "not_started" }),
      "onboarding/update": () => ({
        onboarding: state({ step: "safety" }).onboarding,
      }),
    });
    renderSetup();

    const welcome = await screen.findByRole("heading", {
      name: "Connect your AI to this site",
    });
    expect(welcome).toHaveFocus();
    expect(document.title).toContain("Step 1 of 4: Welcome");

    await userEvent.click(screen.getByRole("button", { name: "Get started" }));

    const heading = await screen.findByRole("heading", {
      name: "Choose what your AI can do",
    });
    await waitFor(() => expect(heading).toHaveFocus());
    expect(document.title).toContain("Step 2 of 4: Choose what your AI can do");

    const update = actions(fetchMock).find(
      (body) => body.get("action") === "MCPMATE_onboarding/update",
    );
    expect(update?.get("step")).toBe("safety");
    expect(update?.get("status")).toBe("in_progress");
  });

  it("shows progress with words and marks the current step", async () => {
    mockAjax({ "onboarding/state": () => state({ step: "safety" }) });
    renderSetup();

    const progress = await screen.findByRole("list", {
      name: "Setup progress",
    });
    const current = progress.querySelector('[aria-current="step"]');
    expect(current).toHaveTextContent("2. Choose what your AI can do");
    expect(current).toHaveTextContent("(current)");
    expect(progress).toHaveTextContent("1. Welcome(done)");
    expect(progress).toHaveTextContent("4. Try it(not started)");
    expect(screen.queryByText("Check your site")).not.toBeInTheDocument();
    expect(
      screen.getByRole("heading", { level: 1, name: "MCPMate setup" }),
    ).toBeInTheDocument();
  });

  it("offers only the read-only switches without MCPMate Pro, and never calls its routes", async () => {
    const base = state({ step: "safety" });
    const fetchMock = mockAjax({
      "onboarding/state": () => ({
        ...base,
        features: { ...base.features, pro_features: false },
      }),
      "db-inspect": () => ({ enabled: false }),
      "cli-commands": () => ({
        commands: [
          { name: "plugin list", risk: "read", enabled: false },
          { name: "user list", risk: "read", enabled: false },
        ],
      }),
    });
    renderSetup();

    expect(
      await screen.findByRole("switch", { name: /Database inspection/ }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("switch", { name: /Read-only commands/ }),
    ).toBeInTheDocument();
    for (const name of [
      /Posts and pages/,
      /Maintenance commands/,
      /Media uploads/,
      /Theme drafts/,
    ]) {
      expect(screen.queryByRole("switch", { name })).not.toBeInTheDocument();
    }
    expect(screen.getByText(/come with MCPMate Pro/)).toBeInTheDocument();

    const called = actions(fetchMock).map((body) => body.get("action"));
    for (const route of [
      "core-proxy",
      "media-upload",
      "theme-drafts",
      "elementor",
    ]) {
      expect(called).not.toContain(`MCPMATE_${route}`);
    }
  });

  it("moves to the last step when an AI app is approved after Add it to your AI opens", async () => {
    let approved = false;
    mockAjax({
      "onboarding/state": () =>
        state({ step: "add_ai", connect_since: "2026-10-26 10:00:00" }),
      "grants/list": () => ({
        // An older app is "already connected", not the new one.
        grants: [
          ...(approved ? [grant()] : []),
          grant({
            grant_id: "g_BBBBBBBBBBBBBBBBBBBBBB",
            client_name: "Cursor",
            created_at: "2026-10-20T09:00:00Z",
            status: "active",
          }),
        ],
        limit: 25,
      }),
      "onboarding/update": () => ({
        onboarding: state({ step: "first_call", grant_id: grant().grant_id })
          .onboarding,
      }),
    });
    renderSetup();

    expect(
      await screen.findByText("Waiting for your approval…"),
    ).toBeInTheDocument();
    expect(
      await screen.findByText(
        "Already connected: Cursor. Connect another app, or continue.",
      ),
    ).toBeInTheDocument();

    approved = true;
    act(() => {
      new FakeChannel("mcpmate").postMessage({
        type: "grant-approved",
        grant_id: grant().grant_id,
        client_name: "Claude",
      });
    });

    // At once on the message, not on the next 3 s poll.
    expect(
      await screen.findByText("Claude is connected.", {}, { timeout: 1000 }),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(
        "Claude is connected. Moving to the next step.",
      ),
    );
    expect(
      await screen.findByRole(
        "heading",
        { name: "Try your first request" },
        { timeout: 3000 },
      ),
    ).toHaveFocus();
  });

  it("says when the app was denied in this browser and keeps waiting", async () => {
    mockAjax({
      "onboarding/state": () =>
        state({ step: "add_ai", connect_since: "2026-10-26 10:00:00" }),
      "grants/list": () => ({ grants: [], limit: 25 }),
    });
    renderSetup();
    await screen.findByText("Waiting for your approval…");

    act(() => {
      new FakeChannel("mcpmate").postMessage({
        type: "grant-denied",
        client_name: "ChatGPT",
      });
    });

    expect(
      await screen.findByText(
        "You denied ChatGPT. To try again, start the connection again in your AI app.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("Waiting for your approval…")).toBeInTheDocument();
  });

  it("shows the first call and completes setup with Done", async () => {
    const fetchMock = mockAjax({
      "onboarding/state": () =>
        state(
          { step: "first_call", grant_id: grant().grant_id },
          {
            grant: grant({ status: "active" }),
            first_call: {
              method: "GET",
              route: "site-info",
              result: "ok",
              message: "",
              at: "2026-10-26T10:01:00Z",
              audit_id: 7,
            },
          },
        ),
      "core-proxy": () => ({ enabled: false }),
      "onboarding/update": () => ({
        onboarding: state({ status: "completed" }).onboarding,
      }),
    });
    renderSetup();

    expect(
      await screen.findByText(
        /It works\. Claude read your site's details at 2026-10-26 10:01:00 UTC\./,
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("GET site-info")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Done" }));

    expect(await screen.findByText("Dashboard page")).toBeInTheDocument();
    const update = actions(fetchMock).find(
      (body) => body.get("action") === "MCPMATE_onboarding/update",
    );
    expect(update?.get("status")).toBe("completed");
  });

  it("waits for the first call with prompts that fit the site", async () => {
    mockAjax({
      "onboarding/state": () =>
        state(
          { step: "first_call", grant_id: grant().grant_id },
          { grant: grant() },
        ),
      "core-proxy": () => ({ enabled: true }),
    });
    renderSetup();

    expect(
      await screen.findByText("Waiting for Claude's first request…"),
    ).toBeInTheDocument();
    // Posts and pages is on: prompt 3 lists posts.
    expect(
      screen.getByText("List my five most recent posts and drafts."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Copy prompt 3" }),
    ).toBeInTheDocument();
  });

  it("asks before skipping and returns to the Dashboard", async () => {
    const fetchMock = mockAjax({
      "onboarding/state": () => state({ step: "safety" }),
      "onboarding/update": () => ({
        onboarding: state({ status: "skipped" }).onboarding,
      }),
    });
    renderSetup();

    await userEvent.click(
      await screen.findByRole("button", { name: "Skip setup" }),
    );
    await userEvent.click(await screen.findByRole("button", { name: "Skip" }));

    expect(await screen.findByText("Dashboard page")).toBeInTheDocument();
    const update = actions(fetchMock).find(
      (body) => body.get("action") === "MCPMATE_onboarding/update",
    );
    expect(update?.get("status")).toBe("skipped");
  });

  it("can be used with the keyboard alone", async () => {
    mockAjax({
      "onboarding/state": () => state({ status: "not_started" }),
      "onboarding/update": () => ({
        onboarding: state({ step: "safety" }).onboarding,
      }),
    });
    renderSetup();
    const user = userEvent.setup();

    await screen.findByRole("heading", {
      name: "Connect your AI to this site",
    });

    // Heading has focus; the next Tab reaches Get started, Enter activates it.
    await user.tab();
    expect(screen.getByRole("button", { name: "Get started" })).toHaveFocus();
    await user.keyboard("{Enter}");

    await waitFor(() =>
      expect(
        screen.getByRole("heading", { name: "Choose what your AI can do" }),
      ).toHaveFocus(),
    );
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Save and continue" }),
      ).toBeEnabled(),
    );
  });
});

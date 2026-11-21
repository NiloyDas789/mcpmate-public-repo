import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, renderPage } from "../../test/renderPage";
import Connections from "./index";
import { clientStatus, revokeReasonLabel, sortClients } from "./logic";
import type { Grant, RegisteredClient } from "../../types/api";

afterEach(() => {
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

const clientsCard = () =>
  screen.getByRole("region", { name: "Registered clients" });
const signInCard = () =>
  screen.getByRole("region", {
    name: "Token lifetimes and client registration",
  });

async function clientRows() {
  let rows: HTMLElement[] = [];
  await waitFor(() => {
    rows = within(within(clientsCard()).getByRole("table"))
      .getAllByRole("row")
      .filter((row) => within(row).queryByRole("rowheader"));
    expect(rows.length).toBeGreaterThan(0);
  });

  return rows;
}

/** What `preferences/update` was sent, in order. */
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

async function expectNoAxeViolations(region: HTMLElement) {
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

describe("Registered clients", () => {
  it("lists each client with its ID, redirect domain, date and status", async () => {
    renderConnections({ scenario: "one" });

    const [unused, claude] = await clientRows();
    expect(within(unused).getByRole("rowheader")).toHaveTextContent(
      "Unknown tool",
    );
    expect(unused).toHaveTextContent("not verified");
    expect(unused).toHaveTextContent("mcpm_c_unused");
    expect(unused).toHaveTextContent("127.0.0.1");
    expect(unused).toHaveTextContent("Never approved");

    expect(claude).toHaveTextContent("verified by claude.ai");
    expect(claude).toHaveTextContent("https://claude.ai/oauth/client.json");
    expect(claude).toHaveTextContent("Connected (1 app)");
    expect(
      within(clientsCard()).getByText("1 of 200 self-registered"),
    ).toBeInTheDocument();
  });

  it("revokes a client with the keyboard, signs out its apps, and can allow it again", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderConnections({ scenario: "one" });

    const [, claude] = await clientRows();
    within(claude).getByRole("button", { name: "Revoke Claude" }).focus();
    await user.keyboard("{Enter}");

    const dialog = await screen.findByRole("alertdialog", {
      name: "Revoke Claude?",
    });
    expect(dialog).toHaveTextContent(
      "1 AI app connected through Claude loses access to this site right away.",
    );
    const keep = within(dialog).getByRole("button", { name: "Keep it" });
    await waitFor(() => expect(keep).toHaveFocus());
    await expectNoAxeViolations(dialog);

    await user.keyboard("{Shift>}{Tab}{/Shift}{Enter}");

    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(
        "Claude is revoked. 1 AI app lost access.",
      ),
    );
    expect(calledRoutes(fetchMock)).toContain("clients/revoke");
    // The row stays; its button becomes Allow again, and keeps focus.
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Allow Claude again" }),
      ).toHaveFocus(),
    );
    // The AI apps table is fetched again: Claude is gone from it.
    await waitFor(() =>
      expect(
        within(screen.getByRole("region", { name: "AI apps" })).getByText(
          "No AI apps connected yet.",
        ),
      ).toBeInTheDocument(),
    );

    const revoked = (await clientRows()).find((row) =>
      within(row).queryByText("Claude"),
    ) as HTMLElement;
    expect(revoked).toHaveTextContent(/Revoked/);
    await user.click(
      within(revoked).getByRole("button", { name: "Allow Claude again" }),
    );
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(
        "Claude can connect again. You approve it here when it asks.",
      ),
    );
    expect(calledRoutes(fetchMock)).toContain("clients/restore");
  }, 20_000);

  it("explains how clients appear when there are none", async () => {
    renderConnections({ scenario: "none" });

    expect(
      await within(clientsCard()).findByText("No AI apps have registered yet."),
    ).toBeInTheDocument();
    expect(
      within(clientsCard()).getByRole("button", { name: "Connect an AI app" }),
    ).toBeInTheDocument();
  });
});

describe("Token lifetimes and client registration", () => {
  it("saves the lifetimes and self-registration with one Save changes", async () => {
    const user = userEvent.setup();
    const { fetchMock, site } = renderConnections({ scenario: "one" });

    // The card is replaced once the settings load: query it after that.
    const minutes = await screen.findByRole("combobox", {
      name: "Access tokens last",
    });
    const card = within(signInCard());
    expect(minutes).toHaveValue("15");
    expect(
      card.getByRole("combobox", { name: "Approve apps again after" }),
    ).toHaveValue("30");

    await user.selectOptions(minutes, "60");
    await user.click(
      card.getByRole("switch", { name: "Let AI apps register themselves" }),
    );
    expect(card.getByText("2 unsaved changes")).toBeInTheDocument();
    await user.click(card.getByRole("button", { name: "Save changes" }));

    await waitFor(() =>
      expect(saves(fetchMock)).toEqual([
        { open_registration: false, access_token_minutes: 60 },
      ]),
    );
    expect(site.preferences().access_token_minutes).toBe(60);
    expect(site.preferences().open_registration).toBe(false);
    expect(await card.findByText("Saved.")).toBeInTheDocument();
    await expectNoAxeViolations(signInCard());
  }, 20_000);

  it("shows the server's refusal next to the setting", async () => {
    const user = userEvent.setup();
    renderConnections({
      scenario: "one",
      settingsErrors: { refresh_token_days: "Must be one of: 7, 30, 60, 90." },
    });

    const days = await screen.findByRole("combobox", {
      name: "Approve apps again after",
    });
    const card = within(signInCard());
    await user.selectOptions(days, "90");
    await user.click(card.getByRole("button", { name: "Save changes" }));

    expect(
      await card.findByText("Must be one of: 7, 30, 60, 90."),
    ).toBeInTheDocument();
    expect(
      card.getByText("Couldn't save. Nothing was changed."),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(
        card.getByRole("combobox", { name: "Approve apps again after" }),
      ).toHaveFocus(),
    );
  });

  it("is where Settings' link lands", async () => {
    renderConnections({
      scenario: "one",
      path: "/connections?section=sign-in",
    });

    await waitFor(() =>
      expect(
        screen.getByRole("heading", {
          name: "Token lifetimes and client registration",
        }),
      ).toHaveFocus(),
    );
  });
});

describe("client logic", () => {
  const client = (overrides: Partial<RegisteredClient>): RegisteredClient => ({
    client_key: "a".repeat(64),
    client_id: "mcpm_c_x",
    kind: "dcr",
    client_name: "App",
    verified_host: null,
    redirect_hosts: [],
    live_grants: 0,
    status: "not_connected",
    created_at: "2026-10-20T10:00:00Z",
    last_used_at: null,
    revoked_at: null,
    ...overrides,
  });

  it("words each status with a tone", () => {
    expect(
      clientStatus(client({ status: "connected", live_grants: 2 })),
    ).toEqual({
      tone: "success",
      text: "Connected (2 apps)",
    });
    expect(clientStatus(client({ status: "unused" })).tone).toBe("warning");
    expect(clientStatus(client({ status: "not_connected" })).text).toBe(
      "Not connected",
    );
    expect(
      clientStatus(
        client({ status: "revoked", revoked_at: "2026-10-26T10:00:00Z" }),
      ).text,
    ).toMatch(/^Revoked /);
  });

  it("lists revoked clients last, newest first otherwise", () => {
    const sorted = sortClients([
      client({ client_name: "old", created_at: "2026-10-01T00:00:00Z" }),
      client({
        client_name: "revoked",
        status: "revoked",
        created_at: "2026-10-25T00:00:00Z",
      }),
      client({ client_name: "new", created_at: "2026-10-20T00:00:00Z" }),
    ]);

    expect(sorted.map((each) => each.client_name)).toEqual([
      "new",
      "old",
      "revoked",
    ]);
  });

  it("says when an app was revoked with its client", () => {
    expect(
      revokeReasonLabel({ revoke_reason: "client_revoked" } as Grant),
    ).toBe("Revoked with its registered client");
  });
});

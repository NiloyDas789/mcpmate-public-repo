import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { ConnectRequestDetails } from "../../types/api";
import { goTo } from "@utils/navigate";
import Connect from "./index";

vi.mock("@utils/navigate", () => ({ goTo: vi.fn() }));

const REQUEST_ID = "Aq3vR9x2Lk0pW7mZ5tY1uB8nC4dE6fG2hJ9kL3sQ0wE";

/** Where Claude sends the browser back after the approval. */
const CALLBACK = "https://claude.ai/api/mcp/auth_callback";

const request: ConnectRequestDetails = {
  ok: true,
  request: REQUEST_ID,
  client: {
    name: "Claude",
    uri: "https://claude.ai",
    vouched_by: "cimd:claude.ai",
    redirect_host: "claude.ai",
    localhost_redirect: false,
  },
  scopes: ["site:read", "site:write"],
  expires_at: "2026-10-25T10:10:00Z",
  site: "http://localhost",
  user: {
    login: "admin",
    display_name: "Site Admin",
    roles: ["administrator"],
  },
  redirect_origin: "https://claude.ai",
};

function mockAjax(redirect: string) {
  const fetchMock = vi
    .fn()
    .mockImplementation(
      async () =>
        new Response(
          JSON.stringify({ success: true, data: { redirect_url: redirect } }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
    );
  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

function renderPage() {
  const client = new QueryClient();

  return render(
    <QueryClientProvider client={client}>
      <Connect />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  SERVER_VARIABLES.connectRequest = request;
});

afterEach(() => {
  SERVER_VARIABLES.connectRequest = null;
  vi.unstubAllGlobals();
  vi.mocked(goTo).mockReset();
});

describe("Connect", () => {
  it("shows the app, who vouches for it, where access goes and who it acts as", () => {
    renderPage();

    expect(
      screen.getByRole("heading", {
        name: "Connect Claude to http://localhost?",
      }),
    ).toBeInTheDocument();
    // Who vouches for it, and where access goes.
    expect(screen.getAllByText("claude.ai", { selector: "code" })).toHaveLength(
      2,
    );
    expect(screen.getByText(/Vouched for by/)).toBeInTheDocument();
    expect(
      screen.getByText("You: Site Admin (admin, administrator)"),
    ).toBeInTheDocument();
    expect(screen.getByText("Make changes")).toBeInTheDocument();
    expect(screen.queryByText(/any program on this computer/)).toBeNull();
  });

  it("approves and goes back to the app's redirect URI", async () => {
    const back = `${CALLBACK}?code=abc&state=s`;
    const fetchMock = mockAjax(back);
    renderPage();

    await userEvent.click(screen.getByRole("button", { name: "Approve" }));

    await waitFor(() => expect(goTo).toHaveBeenCalledWith(back));
    const body = fetchMock.mock.calls[0][1].body as FormData;
    expect(body.get("action")).toBe("MCPMATE_connection/approve");
    expect(body.get("request")).toBe(REQUEST_ID);
    expect(body.get("token")).toBeNull();
    expect(body.get("read_only")).toBe("false");
  });

  it("sends read only when ticked", async () => {
    const fetchMock = mockAjax(CALLBACK);
    renderPage();

    await userEvent.click(screen.getByRole("checkbox", { name: "Read only" }));
    expect(screen.queryByText("Make changes")).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "Approve" }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    expect((fetchMock.mock.calls[0][1].body as FormData).get("read_only")).toBe(
      "true",
    );
  });

  it("denies through connection/reject", async () => {
    const fetchMock = mockAjax(CALLBACK);
    renderPage();

    await userEvent.click(screen.getByRole("button", { name: "Deny" }));

    await waitFor(() => expect(goTo).toHaveBeenCalled());
    expect((fetchMock.mock.calls[0][1].body as FormData).get("action")).toBe(
      "MCPMATE_connection/reject",
    );
  });

  it("never follows a redirect to another origin", async () => {
    mockAjax("https://evil.example/steal");
    renderPage();

    await userEvent.click(screen.getByRole("button", { name: "Approve" }));

    expect(
      await screen.findByText("The approval returned an unexpected address."),
    ).toBeInTheDocument();
    expect(goTo).not.toHaveBeenCalled();
  });

  it("never follows a look-alike origin", async () => {
    const fetchMock = mockAjax("https://claude.ai.evil.test/cb?code=abc");
    renderPage();

    await userEvent.click(screen.getByRole("button", { name: "Deny" }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    // An earlier test's toast may still be on screen: at least one says so.
    expect(
      await screen.findAllByText(
        "The approval returned an unexpected address.",
      ),
    ).not.toHaveLength(0);
    expect(goTo).not.toHaveBeenCalled();
  });

  it("warns about localhost redirects and unverified apps", () => {
    SERVER_VARIABLES.connectRequest = {
      ...request,
      client: {
        ...request.client,
        vouched_by: "dcr",
        redirect_host: "localhost",
        localhost_redirect: true,
      },
    };
    renderPage();

    expect(
      screen.getByText(/any program on this computer/),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Registered automatically, not verified"),
    ).toBeInTheDocument();
  });

  it("shows why a request was refused", () => {
    SERVER_VARIABLES.connectRequest = {
      ok: false,
      request: "",
      code: "mcpmate_approval_request_expired",
      message:
        "This approval link expired. Start connecting from the AI app again.",
    };
    renderPage();

    expect(screen.getByText(/This approval link expired/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Approve" })).toBeNull();
  });

  it("tells a setup wizard in this browser, without the request", async () => {
    const posted: unknown[] = [];
    class Channel {
      constructor(public name: string) {}
      postMessage(data: unknown) {
        posted.push({ name: this.name, data });
      }
      close() {}
    }
    vi.stubGlobal("BroadcastChannel", Channel);
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            success: true,
            data: {
              redirect_url: `${CALLBACK}?code=x`,
              grant: { grant_id: "g_AAAAAAAAAAAAAAAAAAAAAA" },
            },
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
      ),
    );

    renderPage();
    await userEvent.click(screen.getByRole("button", { name: "Approve" }));

    await waitFor(() => expect(goTo).toHaveBeenCalled());
    expect(posted).toEqual([
      {
        name: "mcpmate",
        data: {
          type: "grant-approved",
          grant_id: "g_AAAAAAAAAAAAAAAAAAAAAA",
          client_name: "Claude",
        },
      },
    ]);
    expect(JSON.stringify(posted)).not.toContain(REQUEST_ID);
  });
});

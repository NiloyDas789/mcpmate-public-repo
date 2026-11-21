import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, useLocation } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AppRouteTable } from "./AppRoutes";

function Location() {
  const { pathname, search } = useLocation();
  return <output data-testid="location">{pathname + search}</output>;
}

function renderAt(path: string) {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <MemoryRouter initialEntries={[path]}>
        <AppRouteTable />
        <Location />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const location = () => screen.getByTestId("location").textContent;

beforeEach(() => {
  // Pages load their data; these tests only follow the routes.
  vi.stubGlobal(
    "fetch",
    vi.fn(() => new Promise(() => undefined)),
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  SERVER_VARIABLES.debug = false;
});

describe("old routes", () => {
  it.each([
    ["/connection", "/connections"],
    ["/connected-apps", "/connections"],
    ["/audit-log?tool=cli&result=error", "/activity?tool=cli&result=error"],
    ["/approvals", "/activity/approvals"],
    ["/approvals/12", "/activity/approvals/12"],
  ])("%s redirects to %s", async (from, to) => {
    renderAt(from);

    await waitFor(() => expect(location()).toBe(to));
  });
});

describe("routes", () => {
  it("shows a not-found page inside the shell", async () => {
    renderAt("/nowhere");

    expect(
      await screen.findByRole("heading", { level: 1, name: "Page not found" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("navigation", { name: "MCPMate" }),
    ).toBeInTheDocument();
  });

  it("has the UI kit only while WP_DEBUG is on", async () => {
    renderAt("/ui-kit");
    expect(
      await screen.findByRole("heading", { level: 1, name: "Page not found" }),
    ).toBeInTheDocument();
  });

  it("serves the UI kit with WP_DEBUG", async () => {
    SERVER_VARIABLES.debug = true;
    renderAt("/ui-kit");

    expect(
      await screen.findByRole("heading", { level: 1, name: "UI kit" }),
    ).toBeInTheDocument();
  });

  it("opens Tools and Activity tabs by route", async () => {
    renderAt("/tools/abilities");

    expect(
      await screen.findByRole("tab", { name: "Abilities" }),
    ).toHaveAttribute("aria-selected", "true");
  });

  it("keeps setup and the approval screen outside the navigation", async () => {
    renderAt("/setup");

    await waitFor(() => expect(screen.getByRole("main")).toBeInTheDocument());
    expect(screen.queryByRole("navigation", { name: "MCPMate" })).toBeNull();
  });
});

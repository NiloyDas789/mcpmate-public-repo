import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router";
import { afterEach, describe, expect, it } from "vitest";

import AppShell from "./index";
import PageHeader from "./PageHeader";

// Distinct page components, as in the app: a page's h1 mounts with it.
const DashboardPage = () => <PageHeader title="Dashboard" />;
const ToolsPage = () => (
  <PageHeader title="Tools" intro="What AI apps can do." />
);
const ActivityPage = () => <PageHeader title="Activity" />;

function renderShell(path = "/tools") {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<DashboardPage />} />
            <Route path="tools" element={<ToolsPage />} />
            <Route path="activity" element={<ActivityPage />} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  SERVER_VARIABLES.grants_live = 0;
  SERVER_VARIABLES.pending_approvals = 0;
  SERVER_VARIABLES.links = {
    docs: "",
    support: "",
    review: "",
    feedback: "",
    upgrade: "",
  };
});

describe("AppShell", () => {
  it("starts with a skip link that moves focus into main", async () => {
    const user = userEvent.setup();
    renderShell();

    await user.tab();
    const skip = screen.getByRole("link", { name: "Skip to MCPMate content" });
    expect(skip).toHaveFocus();

    await user.keyboard("{Enter}");
    expect(screen.getByRole("main")).toHaveFocus();
  });

  it("has a labelled navigation of links with the current page marked", () => {
    renderShell("/tools");

    const nav = screen.getByRole("navigation", { name: "MCPMate" });
    const links = within(nav).getAllByRole("link");
    expect(links.map((link) => link.textContent)).toEqual([
      "Dashboard",
      "Connections",
      "Tools",
      "Activity",
      "Settings",
    ]);
    expect(within(nav).getByRole("link", { name: "Tools" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(
      within(nav).getByRole("link", { name: "Dashboard" }),
    ).not.toHaveAttribute("aria-current");
  });

  it("shows the status pill as a link, and the version in words", () => {
    SERVER_VARIABLES.grants_live = 3;
    renderShell();

    expect(
      screen.getByRole("link", { name: "3 AI apps connected" }),
    ).toHaveAttribute("href", "#/connections");
    expect(screen.getByText("Version 1.0.0")).toHaveClass("sr-only");
  });

  it("shows a notice when approvals are waiting", () => {
    SERVER_VARIABLES.pending_approvals = 2;
    renderShell();

    expect(
      screen.getByText("2 requests are waiting for your approval."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Review requests" }),
    ).toHaveAttribute("href", "#/activity/approvals");
  });

  it("moves focus to the h1 after a route change, not on load, and names the tab", async () => {
    const user = userEvent.setup();
    renderShell("/tools");

    expect(
      screen.getByRole("heading", { level: 1, name: "Tools" }),
    ).not.toHaveFocus();
    expect(document.title).toMatch(/^Tools/);

    await user.click(screen.getByRole("link", { name: "Activity" }));

    const heading = await screen.findByRole("heading", {
      level: 1,
      name: "Activity",
    });
    await waitFor(() => expect(heading).toHaveFocus());
    expect(document.title).toMatch(/^Activity/);
  });

  it("lists only configured footer links, marked as opening a new tab", () => {
    SERVER_VARIABLES.links = {
      docs: "https://docs.example",
      support: "",
      review: "",
      feedback: "https://feedback.example",
      upgrade: "",
    };
    renderShell();

    const footer = screen.getByRole("contentinfo");
    expect(
      within(footer)
        .getAllByRole("link")
        .map((link) => link.textContent),
    ).toEqual([
      "Documentation(opens in a new tab)",
      "Share feedback(opens in a new tab)",
    ]);
    expect(
      within(footer).getByRole("link", { name: /Documentation/ }),
    ).toHaveAttribute("target", "_blank");
  });

  it("has no footer when no link is configured", () => {
    renderShell();

    expect(screen.queryByRole("contentinfo")).toBeNull();
  });
});

import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, useLocation } from "react-router";
import { afterEach, describe, expect, it } from "vitest";

import Tabs, { type TabItem } from "./Tabs";

const ITEMS: TabItem[] = [
  { key: "audit", label: "Audit log", to: "/activity" },
  { key: "changes", label: "Change history", to: "/activity/changes" },
  {
    key: "approvals",
    label: "Approvals",
    to: "/activity/approvals",
    count: "2",
    countLabel: "2 waiting",
  },
];

function Harness() {
  const { pathname } = useLocation();
  const activeKey = ITEMS.find((item) => item.to === pathname)?.key ?? "audit";

  return (
    <>
      <Tabs label="Activity" items={ITEMS} activeKey={activeKey}>
        <p>Panel {activeKey}</p>
      </Tabs>
      <output data-testid="path">{pathname}</output>
    </>
  );
}

function renderTabs(path = "/activity") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Harness />
    </MemoryRouter>,
  );
}

const tab = (name: string | RegExp) => screen.getByRole("tab", { name });
const path = () => screen.getByTestId("path").textContent;

afterEach(() => {
  SERVER_VARIABLES.rtl = false;
});

describe("Tabs", () => {
  it("is a named tab list with one tab stop and a labelled panel", () => {
    renderTabs();

    const list = screen.getByRole("tablist", { name: "Activity" });
    expect(within(list).getAllByRole("tab")).toHaveLength(3);
    expect(tab("Audit log")).toHaveAttribute("aria-selected", "true");
    expect(tab("Audit log")).toHaveAttribute("tabindex", "0");
    expect(tab("Change history")).toHaveAttribute("aria-selected", "false");
    expect(tab("Change history")).toHaveAttribute("tabindex", "-1");

    const panel = screen.getByRole("tabpanel", { name: "Audit log" });
    expect(panel).toHaveTextContent("Panel audit");
    expect(tab("Audit log")).toHaveAttribute("aria-controls", panel.id);
    // Only the active tab points at the one panel that exists.
    expect(tab("Change history")).not.toHaveAttribute("aria-controls");
  });

  it("reads the count as part of the tab's name", () => {
    renderTabs();

    expect(tab("Approvals, 2 waiting")).toBeInTheDocument();
  });

  it("moves and activates with Left and Right, wrapping at the ends", async () => {
    const user = userEvent.setup();
    renderTabs();

    await user.click(tab("Audit log"));
    await user.keyboard("{ArrowRight}");

    expect(tab("Change history")).toHaveFocus();
    expect(tab("Change history")).toHaveAttribute("aria-selected", "true");
    expect(tab("Change history")).toHaveAttribute("tabindex", "0");
    expect(tab("Audit log")).toHaveAttribute("tabindex", "-1");
    expect(path()).toBe("/activity/changes");
    expect(screen.getByRole("tabpanel")).toHaveTextContent("Panel changes");

    await user.keyboard("{ArrowLeft}{ArrowLeft}");
    expect(tab(/Approvals/)).toHaveFocus();
    expect(path()).toBe("/activity/approvals");
  });

  it("jumps with Home and End", async () => {
    const user = userEvent.setup();
    renderTabs("/activity/changes");

    await user.click(tab("Change history"));
    await user.keyboard("{End}");
    expect(tab(/Approvals/)).toHaveFocus();
    expect(path()).toBe("/activity/approvals");

    await user.keyboard("{Home}");
    expect(tab("Audit log")).toHaveFocus();
    expect(path()).toBe("/activity");
  });

  it("reverses Left and Right in right-to-left languages", async () => {
    SERVER_VARIABLES.rtl = true;
    const user = userEvent.setup();
    renderTabs();

    await user.click(tab("Audit log"));
    await user.keyboard("{ArrowLeft}");

    expect(tab("Change history")).toHaveFocus();
    expect(path()).toBe("/activity/changes");
  });

  it("leaves the tab list with one Tab, to the panel", async () => {
    const user = userEvent.setup();
    renderTabs();

    await user.click(tab("Audit log"));
    await user.tab();

    expect(screen.getByRole("tabpanel")).toHaveFocus();
  });

  it("activates a tab on click", async () => {
    const user = userEvent.setup();
    renderTabs();

    await user.click(tab(/Approvals/));

    expect(path()).toBe("/activity/approvals");
    expect(tab(/Approvals/)).toHaveAttribute("aria-selected", "true");
  });
});

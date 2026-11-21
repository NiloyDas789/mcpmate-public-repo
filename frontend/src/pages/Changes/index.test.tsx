import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import Changes from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
});

function renderChanges(
  path = "/activity/changes",
  options: Parameters<typeof renderPage>[1] = {},
) {
  return renderPage(<Changes />, {
    path,
    route: "activity/changes",
    ...options,
  });
}

async function rows() {
  const headers = await screen.findAllByRole("rowheader");
  return headers.map((cell) => cell.closest("tr") as HTMLElement);
}

/** The bodies the page sent to changes/list. */
function listRequests(
  fetchMock: ReturnType<typeof vi.fn>,
): Record<string, string>[] {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData &&
        body.get("action") === "MCPMATE_changes/list",
    )
    .map((body) =>
      Object.fromEntries([...body.entries()].map(([k, v]) => [k, String(v)])),
    );
}

describe("Change history", () => {
  it("lists the last 30 days of changes with readable objects", async () => {
    const { fetchMock } = renderChanges();

    const list = await rows();
    expect(list).toHaveLength(4);
    expect(list[0]).toHaveTextContent('Page "Home" (#41)');
    expect(list[0]).toHaveTextContent("Update");
    expect(list[0]).toHaveTextContent("Claude · admin");
    expect(list[1]).toHaveTextContent(
      "Field _elementor_page_settings of post #41",
    );
    expect(list[2]).toHaveTextContent("Option blogdescription");
    expect(list[2]).toHaveTextContent("wp-admin · admin");
    expect(list[3]).toHaveTextContent("Trash");
    expect(list[3]).toHaveTextContent("proxy");
    expect(
      screen.getByText(/Changes made through MCPMate, newest first\. Times in/),
    ).toBeInTheDocument();
    expect(listRequests(fetchMock)[0].from).toMatch(/Z$/);
  });

  it("filters by action in the URL and announces the count", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderChanges();
    await rows();

    // antd Select: open it, then pick an option.
    await user.click(screen.getByLabelText("Action"));
    await user.click(await screen.findByTitle("Trash"));

    await waitFor(() =>
      expect(location.current).toBe("/activity/changes?action=trash"),
    );
    await waitFor(() =>
      expect(listRequests(fetchMock).at(-1)?.change_action).toBe("trash"),
    );
    expect(await rows()).toHaveLength(1);
    await waitFor(() =>
      expect(
        screen
          .getAllByRole("status")
          .map((region) => region.textContent)
          .join(" "),
      ).toContain("1 change matches."),
    );
  });

  it("shows one call's changes whenever they were made", async () => {
    const { fetchMock } = renderChanges("/activity/changes?audit_id=99");

    expect(await rows()).toHaveLength(2);
    const sent = listRequests(fetchMock)[0];
    expect(sent.audit_id).toBe("99");
    expect(sent.from).toBeUndefined();
    expect(
      screen.getByText("Showing the changes of one call (entry #99)."),
    ).toBeInTheDocument();
  });

  it("opens the before and after in a drawer, with the originating call", async () => {
    const user = userEvent.setup();
    renderChanges();
    await rows();

    await user.click(
      screen.getByRole("button", {
        name: 'View before and after: Page "Home" (#41)',
      }),
    );

    const drawer = await screen.findByRole("dialog", {
      name: 'Before and after: Page "Home" (#41)',
    });
    expect(location.current).toBe("/activity/changes?change=12");
    // Changed fields first, with + and − lines and words for screen readers.
    const content = await within(drawer).findByRole("region", {
      name: "Changes to Content",
    });
    expect(within(content).getByText("Removed line:")).toBeInTheDocument();
    expect(content.querySelectorAll("ins")).toHaveLength(2);
    expect(content.querySelectorAll("del")).toHaveLength(1);
    expect(
      within(drawer).getByText("Title").nextElementSibling,
    ).toHaveTextContent("(unchanged) Home");
    // Nine unchanged lines fold behind a disclosure.
    expect(
      within(drawer).getByRole("button", { name: "Show 10 unchanged lines" }),
    ).toHaveAttribute("aria-expanded", "false");
    expect(
      within(drawer).getByRole("link", { name: "Originating call" }),
    ).toHaveAttribute("href", "/activity?entry=99");

    await user.click(within(drawer).getByRole("button", { name: "Close" }));
    await waitFor(() => expect(location.current).toBe("/activity/changes"));
  });

  it("links each row to the call that made it", async () => {
    renderChanges();
    await rows();

    expect(
      screen.getAllByRole("link", {
        name: 'Originating call: Page "Home" (#41)',
      })[0],
    ).toHaveAttribute("href", "/activity?entry=99");
    expect(
      screen.getByRole("link", {
        name: "Originating call: Option blogdescription",
      }),
    ).toHaveAttribute("href", "/activity?entry=97");
    // A change with no audit row has no link.
    expect(
      screen.queryByRole("link", {
        name: 'Originating call: Post "Old news" (#12)',
      }),
    ).toBeNull();
  });

  it("says when change history is turned off, with the way to turn it on", async () => {
    renderChanges("/activity/changes", {
      preferences: { change_history: false },
    });

    expect(
      await screen.findByText(
        "Change history is turned off, so new changes aren't recorded.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Turn it on in Settings" }),
    ).toHaveAttribute("href", "/settings?section=logging");
  });

  it("has an empty state and an error state", async () => {
    const user = userEvent.setup();
    renderChanges("/activity/changes", { scenario: "none" });

    expect(await screen.findByText("No changes yet.")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "View the audit log" }),
    ).toHaveAttribute("href", "#/activity");

    vi.unstubAllGlobals();
    const failing = renderChanges("/activity/changes?type=option", {
      fail: ["changes/list"],
    });
    expect(
      await screen.findByText("Couldn't load the change history."),
    ).toBeInTheDocument();
    failing.site.setFail("changes/list", false);
    await user.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() =>
      expect(
        calledRoutes(failing.fetchMock).filter((r) => r === "changes/list")
          .length,
      ).toBe(2),
    );
  });

  it("has no axe violations, with the drawer open too", async () => {
    renderChanges("/activity/changes?change=12");
    await rows();
    const drawer = await screen.findByRole("dialog");
    await within(drawer).findByRole("region", { name: "Changes to Content" });

    const page = document.querySelector(".el-activity") as HTMLElement;
    for (const region of [page, drawer]) {
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
  }, 120_000);
});

import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import AuditLog from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
});

function renderLog(
  path = "/activity",
  options: Parameters<typeof renderPage>[1] = {},
) {
  return renderPage(<AuditLog />, { path, route: "activity", ...options });
}

const announced = () =>
  screen
    .getAllByRole("status")
    .map((region) => region.textContent ?? "")
    .join(" ");

/** The REST queries the page sent to GET /audit-log. */
function auditQueries(fetchMock: ReturnType<typeof vi.fn>): URLSearchParams[] {
  return fetchMock.mock.calls
    .map(([url]) => String(url))
    .filter((url) => url.includes("/audit-log"))
    .map((url) => new URL(url).searchParams);
}

/** The loaded rows (skeleton rows have no row header). */
async function rows() {
  const headers = await screen.findAllByRole("rowheader");
  return headers.map((cell) => cell.closest("tr") as HTMLElement);
}

describe("Audit log", () => {
  it("shows skeleton rows while loading", () => {
    renderLog("/activity", { hang: ["audit-log"] });

    expect(
      document.querySelectorAll(".el-skeleton").length,
    ).toBeGreaterThanOrEqual(8);
    expect(screen.getAllByText("Loading…").length).toBeGreaterThan(0);
  });

  it("lists the last 7 days with a caption, one row per call", async () => {
    const { fetchMock } = renderLog();

    expect(await rows()).toHaveLength(6);
    expect(
      screen.getByText(/Audit log, newest first\. Times in/),
    ).toBeInTheDocument();
    expect(screen.getByText("6 entries")).toBeInTheDocument();

    const first = (await rows())[1];
    expect(first).toBeDefined();
    expect(first).toHaveTextContent("elementor_save");
    expect(first).toHaveTextContent("post_id 41, patches 2 items");
    expect(first).toHaveTextContent("Claude");
    expect(first).toHaveTextContent("Succeeded");
    expect(within(first).getByRole("rowheader")).toBeInTheDocument();

    const query = auditQueries(fetchMock)[0];
    expect(query.get("from")).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    expect(query.get("order_by")).toBe("created_at");
  });

  it("reads its filters from the URL, old setup links included", async () => {
    const { fetchMock } = renderLog(
      "/activity?tool=connection&grant_id=g_AAAAAAAAAAAAAAAAAAAAAA&result=error",
    );

    await screen.findByText("No entries match these filters.");
    const query = auditQueries(fetchMock)[0];
    expect(query.get("tool")).toBe("connection");
    expect(query.get("grant_id")).toBe("g_AAAAAAAAAAAAAAAAAAAAAA");
    expect(query.get("result")).toBe("error");
    expect(screen.getByText("3 filters active")).toBeInTheDocument();
  });

  it("writes a filter to the URL and announces the count", async () => {
    const user = userEvent.setup();
    renderLog();
    await rows();

    await user.type(
      screen.getByRole("searchbox", { name: "Search" }),
      "home{Enter}",
    );

    await waitFor(() => expect(location.current).toBe("/activity?q=home"));
    await waitFor(() => expect(announced()).toContain("1 entry matches."));
    expect(await rows()).toHaveLength(1);
  });

  it("clears the filters and says so", async () => {
    const user = userEvent.setup();
    renderLog("/activity?result=error&period=all");
    await screen.findByText("2 filters active");

    await user.click(screen.getByRole("button", { name: "Clear filters" }));

    await waitFor(() => expect(location.current).toBe("/activity"));
    await waitFor(() =>
      expect(announced()).toContain("Filters cleared. 6 entries."),
    );
  });

  it("sorts by duration on the server and keeps it in the URL", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderLog();
    await rows();

    await user.click(
      screen.getByRole("button", {
        name: "Duration, not sorted. Select to sort longest first.",
      }),
    );

    await waitFor(() =>
      expect(location.current).toBe("/activity?sort=duration&order=desc"),
    );
    await waitFor(() =>
      expect(auditQueries(fetchMock).at(-1)?.get("order_by")).toBe(
        "duration_ms",
      ),
    );
    expect(announced()).toContain("Sorted by Duration, longest first.");
    expect(
      screen.getByRole("columnheader", { name: /Duration/ }),
    ).toHaveAttribute("aria-sort", "descending");
  });

  it("pages through 20 at a time, moving focus to the caption", async () => {
    const user = userEvent.setup();
    renderLog("/activity?period=all", { scenario: "many" });
    expect(await rows()).toHaveLength(20);

    await user.click(screen.getByRole("button", { name: "Page 2" }));

    await waitFor(() =>
      expect(location.current).toBe("/activity?period=all&page=2"),
    );
    expect(document.activeElement).toHaveTextContent(
      /^Audit log, newest first/,
    );
    expect(announced()).toContain("Page 2 of 3.");
    await waitFor(() =>
      expect(screen.getByText("Showing 21–40 of 45")).toBeInTheDocument(),
    );
  });

  it("opens an entry in a drawer kept in the URL, and closes it", async () => {
    const user = userEvent.setup();
    renderLog();
    await rows();

    await user.click(
      screen.getByRole("button", { name: /^View details: elementor_save · / }),
    );

    const drawer = await screen.findByRole("dialog");
    expect(location.current).toMatch(/^\/activity\?entry=99$/);
    expect(
      within(drawer).getByText('Saved 3 changes to "Home".'),
    ).toBeInTheDocument();
    expect(
      within(drawer).getByRole("heading", {
        name: "Arguments (secrets hidden)",
      }),
    ).toBeInTheDocument();
    expect(
      within(drawer).getByRole("region", { name: "Arguments" }),
    ).toHaveTextContent('"token": "[redacted]"');
    expect(
      within(drawer).getByRole("textbox", { name: "Request ID" }),
    ).toHaveValue("req-1");
    expect(
      within(drawer).getByRole("link", { name: "Open in editor" }),
    ).toHaveAttribute(
      "href",
      expect.stringContaining("post.php?post=41&action=edit"),
    );
    // The changes this call made.
    expect(
      await within(drawer).findByRole("link", {
        name: 'View before and after: Page "Home" (#41)',
      }),
    ).toHaveAttribute("href", "/activity/changes?change=12");

    await user.click(within(drawer).getByRole("button", { name: "Close" }));
    await waitFor(() => expect(location.current).toBe("/activity"));
  });

  it("fetches an entry from a link when it isn't on the page", async () => {
    const { fetchMock } = renderLog("/activity?entry=97");

    const drawer = await screen.findByRole("dialog");
    expect(
      await within(drawer).findByText("Waiting for approval:"),
    ).toBeInTheDocument();
    expect(
      within(drawer).getByRole("link", { name: "Review request" }),
    ).toHaveAttribute("href", "/activity/approvals");
    expect(
      auditQueries(fetchMock).some((query) => query.get("id") === "97"),
    ).toBe(true);
  });

  it("says when a linked entry is gone", async () => {
    renderLog("/activity?entry=5");

    expect(
      await screen.findByText("This entry isn't in the audit log any more."),
    ).toBeInTheDocument();
  });

  it("filters to one app from the drawer", async () => {
    const user = userEvent.setup();
    renderLog("/activity?entry=100");
    const drawer = await screen.findByRole("dialog");

    await user.click(
      await within(drawer).findByRole("button", { name: "Show only this app" }),
    );

    await waitFor(() =>
      expect(location.current).toBe(
        "/activity?grant_id=g_AAAAAAAAAAAAAAAAAAAAAA",
      ),
    );
  });

  it("shows an error with Try again", async () => {
    const user = userEvent.setup();
    const { site, fetchMock } = renderLog("/activity", { fail: ["audit-log"] });

    expect(
      await screen.findByText("Couldn't load the audit log."),
    ).toBeInTheDocument();
    site.setFail("audit-log", false);
    await user.click(screen.getByRole("button", { name: "Try again" }));

    expect(await rows()).toHaveLength(6);
    expect(
      calledRoutes(fetchMock).filter((route) => route === "audit-log").length,
    ).toBeGreaterThan(1);
  });

  it("offers all time when the last 7 days are empty, then Connect an AI app", async () => {
    const user = userEvent.setup();
    renderLog("/activity", { scenario: "none" });

    await screen.findByText("Nothing logged in the last 7 days.");
    await user.click(screen.getByRole("button", { name: "Show all time" }));

    expect(await screen.findByText("Nothing logged yet.")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Connect an AI app" }),
    ).toHaveAttribute("href", "#/connections");
  });

  it("asks before removing old entries, then removes them", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderLog("/activity", { scenario: "many" });
    await rows();

    expect(
      await screen.findByText(
        /Entries are kept for 90 days, then removed automatically\./,
      ),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", {
        name: "Remove entries older than 90 days now",
      }),
    );

    const dialog = await screen.findByRole("alertdialog", {
      name: "Remove old entries now?",
    });
    expect(dialog).toHaveTextContent(
      "This removes 1,240 entries older than 90 days from this site. They can't be recovered. Entries from the last 90 days stay.",
    );
    await waitFor(() =>
      expect(document.activeElement).toHaveTextContent("Keep entries"),
    );
    await user.click(
      within(dialog).getByRole("button", { name: "Remove 1,240 entries" }),
    );

    await waitFor(() =>
      expect(announced()).toContain("Removed 1,240 entries."),
    );
    expect(calledRoutes(fetchMock)).toContain("audit-log/purge");
  });

  it("says there is nothing to remove instead of asking", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderLog();
    await rows();
    await screen.findByText(/Entries are kept for 90 days/);

    await user.click(
      screen.getByRole("button", {
        name: "Remove entries older than 90 days now",
      }),
    );

    expect(
      await screen.findByText(
        "Nothing to remove: every entry is newer than 90 days.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByRole("alertdialog")).toBeNull();
    expect(calledRoutes(fetchMock)).not.toContain("audit-log/purge");
  });

  it("has no axe violations, with the drawer open too", async () => {
    renderLog("/activity?entry=99");
    await rows();
    const drawer = await screen.findByRole("dialog");
    await within(drawer).findByRole("region", { name: "Arguments" });

    // The page (the shell's <main> is not mounted here) and the drawer.
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

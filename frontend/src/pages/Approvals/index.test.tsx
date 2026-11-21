import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { location, renderPage } from "../../test/renderPage";
import Approvals from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
});

function renderApprovals(
  path = "/activity/approvals",
  options: Parameters<typeof renderPage>[1] = {},
) {
  return renderPage(<Approvals />, {
    path,
    route: "activity/approvals",
    ...options,
  });
}

/** The `status` each approvals request asked for. */
function statuses(fetchMock: ReturnType<typeof vi.fn>): string[] {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData && body.get("action") === "MCPMATE_approvals",
    )
    .map((body) => String(body.get("status")));
}

describe("Approvals", () => {
  it("lists waiting requests in a captioned table, in plain words", async () => {
    const { fetchMock } = renderApprovals();

    const table = await screen.findByRole("table", {
      name: /Approval requests, newest first/,
    });
    const row = (await within(table).findAllByRole("rowheader"))[0].closest(
      "tr",
    ) as HTMLElement;
    expect(row).toHaveTextContent("search-replace 'http://old.example'");
    expect(row).toHaveTextContent("Waiting for approval");
    expect(row).toHaveTextContent(/in \d+ min/);
    expect(
      within(row).getByRole("link", { name: /^Review request #12: / }),
    ).toHaveAttribute("href", "/activity/approvals/12");
    expect(statuses(fetchMock)).toEqual(["pending"]);
    // No jargon from before the redesign.
    expect(document.body).not.toHaveTextContent(
      /AI client|Destructive|Executed/,
    );
  });

  it("keeps the filter in the URL, as a labelled radio group, and announces the count", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderApprovals();
    await screen.findByRole("table");

    const group = screen.getByRole("radiogroup", { name: "Show" });
    await user.click(within(group).getByRole("radio", { name: "All" }));

    await waitFor(() => expect(location.current).toContain("status=all"));
    await waitFor(() => expect(statuses(fetchMock).at(-1)).toBe(""));
    expect(await screen.findByRole("status")).toHaveTextContent("4 requests");
    expect(screen.getByRole("table")).toHaveTextContent("Run");
    expect(screen.getByRole("table")).toHaveTextContent("Expired");
  });

  it("says when nothing is waiting, with one way on", async () => {
    const user = userEvent.setup();
    renderApprovals("/activity/approvals", { scenario: "none" });

    expect(
      await screen.findByRole("heading", { name: "Nothing waiting for you." }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "View all requests" }));
    await waitFor(() => expect(location.current).toContain("status=all"));
  });

  it("shows a load error with Try again", async () => {
    renderApprovals("/activity/approvals", { scenario: "errors" });

    expect(
      await screen.findByText("Couldn't load approval requests."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeVisible();
  });
});

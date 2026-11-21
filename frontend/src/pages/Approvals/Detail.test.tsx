import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, renderPage } from "../../test/renderPage";
import ApprovalDetail from "./Detail";
import { requestName } from "./labels";

afterEach(() => {
  vi.unstubAllGlobals();
});

function renderDetail(id = 12, options: Parameters<typeof renderPage>[1] = {}) {
  return renderPage(<ApprovalDetail />, {
    path: `/activity/approvals/${id}`,
    route: "activity/approvals/:id",
    ...options,
  });
}

const heading = (id = 12) =>
  screen.findByRole("heading", { name: `Approval request #${id}` });

describe("ApprovalDetail", () => {
  it("shows what will run, its preview and when it expires; focus starts on its heading", async () => {
    renderDetail();

    const title = await heading();
    await waitFor(() => expect(title).toHaveFocus());
    expect(screen.getByText("Waiting for approval")).toBeInTheDocument();
    expect(
      screen.getByText(
        "search-replace 'http://old.example' 'https://example.com'",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("Niloy (admin)")).toBeInTheDocument();
    expect(
      screen.getByRole("region", { name: "Preview details" }),
    ).toHaveTextContent('"rows": 214');
    expect(
      screen.getByText(
        "An AI app is asking to do something that's hard to undo.",
      ).parentElement,
    ).toHaveTextContent(/Expires in \d+ min\.? · /);
    // The site's timezone, not a raw UTC string.
    expect(document.body).not.toHaveTextContent(/\d{2}:\d{2}:\d{2} UTC/);
  });

  it("approves once, keeps the outcome on the page, announces it and keeps focus", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderDetail();
    await heading();

    await user.click(
      screen.getByRole("button", { name: "Approve and run search-replace" }),
    );

    await waitFor(() =>
      expect(screen.getAllByText("Approved and run.").length).toBeGreaterThan(
        1,
      ),
    );
    expect(screen.getByText("Run")).toBeInTheDocument();
    expect(
      screen.getByRole("region", { name: "Result details" }),
    ).toHaveTextContent('"replaced": 214');
    expect(
      screen.queryByRole("button", { name: /^Approve and run/ }),
    ).not.toBeInTheDocument();
    await waitFor(() =>
      expect(
        screen.getByRole("heading", { name: "Approval request #12" }),
      ).toHaveFocus(),
    );
    expect(
      calledRoutes(fetchMock).filter((route) => route === "approvals/approve"),
    ).toHaveLength(1);
  });

  it("asks before rejecting: the dialog opens on Cancel and names what won't run", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderDetail();
    await heading();

    await user.click(screen.getByRole("button", { name: "Reject request" }));
    const dialog = await screen.findByRole("alertdialog", {
      name: "Reject this request?",
    });
    await waitFor(() =>
      expect(
        within(dialog).getByRole("button", { name: "Cancel" }),
      ).toHaveFocus(),
    );
    expect(dialog).toHaveTextContent(
      "The request to run search-replace won't run.",
    );
    expect(calledRoutes(fetchMock)).not.toContain("approvals/reject");
    const results = await axe.run(dialog);
    expect(results.violations).toEqual([]);

    await user.click(
      within(dialog).getByRole("button", { name: "Reject request" }),
    );
    expect(await screen.findByText("Rejected")).toBeInTheDocument();
    expect(screen.getAllByText("Request rejected.").length).toBeGreaterThan(0);
    expect(calledRoutes(fetchMock)).toContain("approvals/reject");
  });

  it("shows a refused approval on the page, not only in a toast", async () => {
    const user = userEvent.setup();
    renderDetail(12, { fail: ["approvals/approve"] });
    await heading();

    await user.click(
      screen.getByRole("button", { name: "Approve and run search-replace" }),
    );

    const alert = await screen.findByText("Couldn't approve this request.");
    expect(alert.closest('[role="alert"]')).not.toBeNull();
    expect(
      screen.getByRole("button", { name: "Approve and run search-replace" }),
    ).toBeEnabled();
  });

  it("says so, assertively, when an approved request fails as it runs", async () => {
    const user = userEvent.setup();
    renderDetail(12, { approvalFails: true });
    await heading();

    await user.click(
      screen.getByRole("button", { name: "Approve and run search-replace" }),
    );

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Approved, but it failed when it ran.",
    );
    expect(
      screen.getByText('"search-replace" is not enabled on this site.'),
    ).toBeInTheDocument();
    expect(
      screen.getByText("mcpmate_cli_command_disabled"),
    ).toBeInTheDocument();
  });

  it("shows a load error with Try again and a way back", async () => {
    renderDetail(99);

    expect(
      await screen.findByText("Couldn't load this approval request."),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeVisible();
    expect(
      screen.getByRole("link", { name: "All approval requests" }),
    ).toHaveAttribute("href", "/activity/approvals");
  });
});

describe("requestName", () => {
  it("names the command, not its arguments", () => {
    expect(
      requestName({
        tool: "cli",
        action: "search-replace",
        args: { command: "search-replace 'a' 'b'" },
      }),
    ).toBe("search-replace");
    expect(
      requestName({
        tool: "cli",
        action: "plugin.deactivate",
        args: { command: "plugin deactivate akismet" },
      }),
    ).toBe("plugin deactivate");
    expect(
      requestName({
        tool: "abilities",
        action: "shop:delete-drafts",
        args: { name: "shop/delete-drafts" },
      }),
    ).toBe("shop/delete-drafts");
  });
});

import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, renderPage } from "../../test/renderPage";
import Settings from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
  SERVER_VARIABLES.pro = undefined;
  SERVER_VARIABLES.links.upgrade = "";
});

function renderSettings(pro?: { installed: boolean; active: boolean }) {
  SERVER_VARIABLES.pro = pro;

  return renderPage(<Settings />, { path: "/settings", route: "settings" });
}

const licenseCard = async () =>
  (await screen.findByRole("heading", { name: "License" })).closest(
    "section",
  ) as HTMLElement;

describe("Settings → License", () => {
  it("is not shown, and asks nothing, without MCPMate Pro", async () => {
    const { fetchMock } = renderSettings();

    await screen.findByRole("heading", { name: "Advanced" });

    expect(screen.queryByRole("heading", { name: "License" })).toBeNull();
    expect(calledRoutes(fetchMock)).not.toContain("license/status");
  });

  it("activates a key, then shows it masked and removes it after a confirmation", async () => {
    const user = userEvent.setup();
    renderSettings({ installed: true, active: false });
    const card = await licenseCard();

    const field = await within(card).findByLabelText("License key");
    const activate = within(card).getByRole("button", {
      name: "Activate license",
    });
    expect(activate).toBeDisabled();

    await user.type(field, "VALID-PRO-KEY");
    await user.click(activate);

    expect(
      await within(card).findByText("MCPMate Pro is active for localhost."),
    ).toBeInTheDocument();
    expect(within(card).getByText("*********-KEY")).toBeInTheDocument();
    expect(within(card).queryByDisplayValue("VALID-PRO-KEY")).toBeNull();

    await user.click(
      within(card).getByRole("button", { name: "Remove license" }),
    );
    const dialog = await screen.findByRole("alertdialog", {
      name: "Remove the license from this site?",
    });
    await user.click(
      within(dialog).getByRole("button", { name: "Remove license" }),
    );

    expect(
      await within(card).findByLabelText("License key"),
    ).toBeInTheDocument();
  });

  it("shows the license server's refusal and keeps the form", async () => {
    const user = userEvent.setup();
    renderSettings({ installed: true, active: false });
    const card = await licenseCard();

    await user.type(await within(card).findByLabelText("License key"), "nope");
    await user.click(
      within(card).getByRole("button", { name: "Activate license" }),
    );

    expect(await within(card).findByRole("alert")).toHaveTextContent(
      "This license key is not valid or not active.",
    );
    expect(within(card).getByLabelText("License key")).toHaveValue("nope");
  });

  it("links to the upgrade page only when one is configured", async () => {
    SERVER_VARIABLES.links.upgrade = "https://example.com/pro";
    renderSettings({ installed: true, active: false });
    const card = await licenseCard();

    const link = await within(card).findByRole("link", {
      name: /Get a license key/,
    });
    expect(link).toHaveAttribute("href", "https://example.com/pro");
    expect(link).toHaveAttribute("target", "_blank");
  });
});

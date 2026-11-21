import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, renderPage } from "../../test/renderPage";
import type { FakeSiteOptions } from "../../test/fakeSite";
import Settings from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
  SERVER_VARIABLES.pro = undefined;
  SERVER_VARIABLES.chat = undefined;
});

function renderSettings(chat: FakeSiteOptions["chat"]) {
  SERVER_VARIABLES.pro = { installed: true, active: true };

  return renderPage(<Settings />, {
    path: "/settings",
    route: "settings",
    chat,
  });
}

const card = () => screen.findByRole("form", { name: "Chat" });

describe("Settings → Chat", () => {
  it("checks the key, saves it, and never shows it again", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings({
      has_key: false,
      enabled: false,
      ready: false,
    });
    const form = await card();

    const key = within(form).getByLabelText("Anthropic API key");
    expect(key).toHaveAttribute("type", "password");
    expect(
      within(form).getByRole("switch", { name: "Turn on Chat" }),
    ).toBeDisabled();

    await user.type(key, "not-a-key");
    await user.click(
      within(form).getByRole("button", { name: "Save changes" }),
    );
    expect(
      await within(form).findAllByText(
        /does not look like an Anthropic API key/,
      ),
    ).not.toHaveLength(0);

    await user.clear(key);
    await user.type(key, "sk-ant-api03-abcdefghijklmnopqrstuvwxyz");
    await user.click(
      within(form).getByRole("switch", { name: "Turn on Chat" }),
    );
    await user.click(
      within(form).getByRole("button", { name: "Save changes" }),
    );

    await waitFor(() =>
      expect(within(form).getByLabelText("Anthropic API key")).toHaveValue(""),
    );
    expect(
      within(form).getByText(/A key is saved\. It is never shown again/),
    ).toBeVisible();
    expect(
      within(form).getByRole("switch", { name: "Turn on Chat" }),
    ).toBeChecked();
    expect(calledRoutes(fetchMock)).toContain("chat/settings/update");
  });

  it("offers to remove a saved key", async () => {
    const user = userEvent.setup();
    renderSettings({});
    const form = await card();

    await user.click(
      within(form).getByRole("button", { name: "Remove the saved key" }),
    );
    expect(
      within(form).getByRole("switch", { name: "Turn on Chat" }),
    ).not.toBeChecked();
    await user.click(
      within(form).getByRole("button", { name: "Save changes" }),
    );

    expect(
      await within(form).findByText(/Create one in the Claude Console/),
    ).toBeVisible();
  });
});

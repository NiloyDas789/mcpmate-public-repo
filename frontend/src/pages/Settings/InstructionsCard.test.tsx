import {
  act,
  fireEvent,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axe from "axe-core";
import { afterEach, describe, expect, it, vi } from "vitest";

import { calledRoutes, location, renderPage } from "../../test/renderPage";
import type { FakeSiteOptions } from "../../test/fakeSite";
import Settings from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
  SERVER_VARIABLES.pro = undefined;
});

function renderSettings(
  options: FakeSiteOptions & { path?: string; pro?: boolean } = {},
) {
  SERVER_VARIABLES.pro =
    options.pro === false ? undefined : { installed: true, active: true };

  return renderPage(<Settings />, {
    path: "/settings",
    route: "settings",
    ...options,
  });
}

const card = async () =>
  screen.findByRole("form", {
    name: "AI instructions",
  }) as Promise<HTMLElement>;

const textField = () =>
  screen.getByRole("textbox", { name: "Instructions" }) as HTMLTextAreaElement;

/** The `site-instructions/update` requests: [enabled, content]. */
function saves(fetchMock: ReturnType<typeof vi.fn>): string[][] {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData &&
        body.get("action") === "MCPMATE_site-instructions/update",
    )
    .map((body) => [String(body.get("enabled")), String(body.get("content"))]);
}

describe("Settings → AI instructions", () => {
  it("is not shown, and asks nothing, without MCPMate Pro", async () => {
    const { fetchMock } = renderSettings({ pro: false });

    await screen.findByRole("heading", { name: "Advanced" });

    expect(
      screen.queryByRole("heading", { name: "AI instructions" }),
    ).toBeNull();
    expect(calledRoutes(fetchMock)).not.toContain("site-instructions");
  });

  it("sits after Access and safety", async () => {
    renderSettings();
    await card();
    await screen.findByRole("combobox", { name: "Keep the audit log for" });

    expect(
      screen.getAllByRole("heading", { level: 2 }).map((h) => h.textContent),
    ).toEqual([
      "Access and safety",
      "AI instructions",
      "Logging and history",
      "Advanced",
      "License",
    ]);
  });

  it("saves the text and the switch together", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings();
    const form = await card();

    await user.click(
      within(form).getByRole("switch", {
        name: "Send these instructions to AI apps",
      }),
    );
    await user.type(textField(), "Use British English.");
    expect(within(form).getByText("2 unsaved changes")).toBeInTheDocument();
    expect(within(form).getByText(/^20 of 8,192 bytes/)).toBeInTheDocument();

    await user.click(
      within(form).getByRole("button", { name: "Save changes" }),
    );

    await waitFor(() =>
      expect(saves(fetchMock)).toEqual([["true", "Use British English."]]),
    );
    expect(await within(form).findByText("Saved.")).toBeInTheDocument();
    expect(within(form).getByText(/Last saved by Niloy/)).toBeInTheDocument();
  });

  it("refuses text over 8 KB before sending it", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings();
    const form = await card();

    fireEvent.change(textField(), { target: { value: "é".repeat(4097) } });

    expect(textField()).toHaveAttribute("aria-invalid", "true");
    expect(
      within(form).getByText(
        "The instructions are 8,194 bytes. Keep them to 8,192 bytes (8 KB) or less.",
      ),
    ).toBeInTheDocument();
    expect(
      within(form).getByRole("button", { name: "Preview" }),
    ).toBeDisabled();

    await user.click(
      within(form).getByRole("button", { name: "Save changes" }),
    );

    expect(
      within(form).getByText("Fix 1 setting to save."),
    ).toBeInTheDocument();
    expect(saves(fetchMock)).toEqual([]);
    await waitFor(() => expect(textField()).toHaveFocus());
  });

  it("shows the text as text, never as HTML", async () => {
    renderSettings({
      path: "/settings?preview=instructions",
      siteInstructions: { enabled: true, content: "<b>Bold</b> words" },
    });
    await card();

    expect(textField().value).toBe("<b>Bold</b> words");
    const preview = await screen.findByRole("region", {
      name: "Instructions AI apps receive",
    });
    expect(preview.textContent).toContain("<b>Bold</b> words");
    expect(preview.querySelector("b")).toBeNull();
  });

  it("previews the draft in a drawer kept in the URL", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSettings({
      siteInstructions: { enabled: true, content: "Saved text." },
    });
    const form = await card();

    fireEvent.change(textField(), { target: { value: "Draft text." } });
    await user.click(within(form).getByRole("button", { name: "Preview" }));

    const drawer = await screen.findByRole("dialog", {
      name: "What AI apps receive",
    });
    expect(location.current).toContain("preview=instructions");
    const preview = await within(drawer).findByRole("region", {
      name: "Instructions AI apps receive",
    });
    expect(preview.textContent).toContain(
      "<site-owner-instructions>\nDraft text.\n</site-owner-instructions>",
    );
    expect(preview.textContent).not.toContain("Saved text.");
    expect(saves(fetchMock)).toEqual([]);

    const result = await axe.run(drawer, {
      rules: { "color-contrast": { enabled: false } },
    });
    expect(result.violations.map((violation) => violation.id)).toEqual([]);

    act(() => {
      fireEvent.keyDown(within(drawer).getByRole("heading", { level: 2 }), {
        key: "Escape",
        keyCode: 27,
      });
    });
    await waitFor(() =>
      expect(location.current).not.toContain("preview=instructions"),
    );
  });

  it("says when AI apps get only MCPMate's own instructions", async () => {
    renderSettings({ path: "/settings?preview=instructions" });

    expect(
      await screen.findByText(
        "Your instructions are off or empty, so AI apps get only MCPMate's own instructions.",
      ),
    ).toBeInTheDocument();
  });

  it("moves to the section from ?section=instructions", async () => {
    renderSettings({ path: "/settings?section=instructions" });

    await waitFor(() =>
      expect(
        screen.getByRole("heading", { name: "AI instructions" }),
      ).toHaveFocus(),
    );
  });

  it("shows an error with Try again", async () => {
    const user = userEvent.setup();
    const { site } = renderSettings({ fail: ["site-instructions"] });

    expect(
      await screen.findByText("Couldn't load the AI instructions."),
    ).toBeInTheDocument();
    site.setFail("site-instructions", false);
    const section = screen
      .getByRole("heading", { name: "AI instructions" })
      .closest("section") as HTMLElement;
    await user.click(
      within(section).getByRole("button", { name: "Try again" }),
    );

    expect(await card()).toBeInTheDocument();
  });
});

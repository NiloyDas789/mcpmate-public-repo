import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { renderPage } from "../../test/renderPage";
import SandboxPanel from "./SandboxPanel";

afterEach(() => {
  vi.unstubAllGlobals();
});

const renderSandbox = (
  path = "/tools/sandbox",
  scenario: "one" | "none" = "one",
) => renderPage(<SandboxPanel />, { path, route: "tools/sandbox", scenario });

/** The admin-ajax actions the page called, with their form fields. */
function calls(
  fetchMock: ReturnType<typeof vi.fn>,
  action: string,
): Record<string, string>[] {
  return fetchMock.mock.calls
    .map(([, init]) => init?.body)
    .filter(
      (body): body is FormData =>
        body instanceof FormData && body.get("action") === `MCPMATE_${action}`,
    )
    .map((body) =>
      Object.fromEntries(
        [...body.entries()].map(([key, value]) => [key, String(value)]),
      ),
    );
}

async function rows(): Promise<HTMLElement[]> {
  const table = await screen.findByRole("table", {
    name: "2 sandbox plugins of at most 10.",
  });

  return (await within(table).findAllByRole("rowheader")).map(
    (cell) => cell.closest("tr") as HTMLElement,
  );
}

describe("Tools → Sandbox", () => {
  it("lists the plugins, whether each runs, and the error that stopped one", async () => {
    renderSandbox();

    const [running, crashed] = await rows();
    expect(running).toHaveTextContent("Booking tweaks");
    expect(running).toHaveTextContent("Running");
    expect(crashed).toHaveTextContent("Stopped after an error");
    expect(crashed).toHaveTextContent(
      "Error: Uncaught Error: Call to undefined function seo_meta_x() (seo-meta/seo-meta.php, line 12)",
    );
    // Nothing is enabled from this page.
    expect(screen.queryByRole("button", { name: /enable/i })).toBeNull();
  });

  it("turns a plugin off at once", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSandbox();

    const [running] = await rows();
    await user.click(
      within(running).getByRole("button", { name: "Turn off: Booking tweaks" }),
    );

    await waitFor(() => expect(running).toHaveTextContent("Off"));
    expect(calls(fetchMock, "sandbox/disable")).toEqual([
      expect.objectContaining({ slug: "booking-tweaks" }),
    ]);
  });

  it("asks before running a crashed plugin again", async () => {
    const user = userEvent.setup();
    const { fetchMock } = renderSandbox();

    const [, crashed] = await rows();
    await user.click(
      within(crashed).getByRole("button", { name: "Run again: SEO meta" }),
    );

    const dialog = await screen.findByRole("alertdialog", {
      name: "Run this plugin again?",
    });
    expect(dialog).toHaveTextContent("seo_meta_x()");
    expect(calls(fetchMock, "sandbox/retry")).toEqual([]);

    await user.click(within(dialog).getByRole("button", { name: "Run again" }));
    await waitFor(() => expect(crashed).toHaveTextContent("Running"));
    expect(calls(fetchMock, "sandbox/retry")).toEqual([
      expect.objectContaining({ slug: "seo-meta" }),
    ]);
  });

  it("shows a plugin's files and one file's code as text", async () => {
    const user = userEvent.setup();
    renderSandbox("/tools/sandbox?plugin=booking-tweaks");

    const drawer = await screen.findByRole("dialog", {
      name: "Booking tweaks",
    });
    expect(
      await within(drawer).findByText(
        /2 files, .* WordPress loads booking-tweaks\.php\./,
      ),
    ).toBeInTheDocument();

    await user.click(
      within(drawer).getByRole("button", { name: "inc/form.php" }),
    );
    const code = await within(drawer).findByRole("region", {
      name: "Code of inc/form.php",
    });
    expect(code).toHaveTextContent("defined('ABSPATH') || exit;");
  });

  it("explains what a sandbox plugin is when there are none", async () => {
    renderSandbox("/tools/sandbox", "none");

    expect(
      await screen.findByText("No sandbox plugins yet."),
    ).toBeInTheDocument();
  });
});
